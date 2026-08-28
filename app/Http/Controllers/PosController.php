<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Movimiento;
use App\Models\Venta;
use App\Models\VentaDetalle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    private const CART_KEY = 'pos.cart';

    private function cartIds(): array
    {
        return array_values(array_map('intval', session(self::CART_KEY, [])));
    }

    private function setCart(array $ids): void
    {
        session([self::CART_KEY => array_values(array_map('intval', $ids))]);
    }

    private function estadoError(Item $item): ?string
    {
        return match ($item->estado) {
            'RESERVADO' => "El equipo {$item->codigo} está RESERVADO y no puede venderse desde POS.",
            'REPARACION' => "El equipo {$item->codigo} está en REPARACIÓN y no puede venderse desde POS.",
            'VENDIDO' => "El equipo {$item->codigo} ya fue vendido.",
            'BAJA' => "El equipo {$item->codigo} está dado de baja.",
            default => null,
        };
    }

    private function precioError(Item $item): ?string
    {
        if ($item->precio === null || (string) $item->precio === '') {
            return "El equipo {$item->codigo} no tiene un precio válido asignado.";
        }

        try {
            if ($this->precioACentavos($item->precio) < 0) {
                return "El equipo {$item->codigo} no tiene un precio válido asignado.";
            }
        } catch (\UnexpectedValueException) {
            return "El equipo {$item->codigo} no tiene un precio válido asignado.";
        }

        return null;
    }

    /**
     * Convierte un decimal(12,2) proveniente de la BD a centavos enteros.
     *
     * Acepta "0" → 0, "0.00" → 0, "1" → 100, "1.2" → 120, "1.20" → 120,
     * "19.99" → 1999. Rechaza nulos, vacíos, no numéricos y más de 2 decimales.
     * No usa punto flotante: aritmética monetaria en enteros.
     */
    private function precioACentavos(int|float|string|null $precio): int
    {
        if ($precio === null || (string) $precio === '') {
            throw new \UnexpectedValueException('Precio inválido.');
        }

        $valor = trim((string) $precio);

        if (! preg_match('/^-?\d+(\.\d{1,2})?$/', $valor)) {
            throw new \UnexpectedValueException('Precio inválido.');
        }

        $negativo = str_starts_with($valor, '-');
        $digitos = $negativo ? substr($valor, 1) : $valor;

        [$enteros, $fraccion] = array_pad(explode('.', $digitos), 2, null);
        $fraccion = $fraccion === null ? '00' : str_pad($fraccion, 2, '0');

        $centavos = ((int) $enteros) * 100 + (int) $fraccion;

        return $negativo ? -$centavos : $centavos;
    }

    /**
     * Convierte centavos enteros a su representación decimal exacta "123.45".
     * Aritmética entera (división y módulo por 100), sin paso por float.
     */
    private function centavosAPrecio(int $centavos): string
    {
        $signo = $centavos < 0 ? '-' : '';
        $absoluto = abs($centavos);

        return $signo.sprintf('%d.%02d', intdiv($absoluto, 100), $absoluto % 100);
    }

    /**
     * Punto de venta: escáner + carrito (session) + confirmación.
     */
    public function index()
    {
        $ids = $this->cartIds();
        $items = collect();

        if ($ids !== []) {
            $items = Item::query()
                ->whereIn('id', $ids)
                ->with(['categoria', 'ubicacion'])
                ->get()
                ->keyBy('id');

            // Preserva el orden de captura del carrito.
            $items = collect($ids)
                ->map(fn (int $id) => $items->get($id))
                ->filter();
        }

        $totalCentavos = $items->sum(function (Item $item) {
            try {
                return $this->precioACentavos($item->precio);
            } catch (\UnexpectedValueException) {
                return 0;
            }
        });

        return view('pos.index', [
            'items' => $items->values(),
            'total' => $this->centavosAPrecio($totalCentavos),
            'formasPago' => Venta::FORMAS_PAGO,
        ]);
    }

    /**
     * Agrega un equipo al carrito escaneando su código (ITM-000123).
     * Normalización determinista: trim + uppercase + lookup exacto por codigo.
     */
    public function add(Request $request)
    {
        $codigo = strtoupper(trim((string) $request->get('codigo', '')));

        if ($codigo === '' || mb_strlen($codigo) > 40) {
            throw ValidationException::withMessages([
                'codigo' => 'Escanea el código del equipo (ej. ITM-000123).',
            ]);
        }

        $item = Item::query()->where('codigo', $codigo)->first();

        if (! $item instanceof Item) {
            throw ValidationException::withMessages([
                'codigo' => "No existe un equipo con el código {$codigo}.",
            ]);
        }

        if ($error = $this->estadoError($item)) {
            throw ValidationException::withMessages(['codigo' => $error]);
        }

        if ($error = $this->precioError($item)) {
            throw ValidationException::withMessages(['codigo' => $error]);
        }

        $ids = $this->cartIds();

        if (in_array($item->id, $ids, true)) {
            throw ValidationException::withMessages([
                'codigo' => "El equipo {$item->codigo} ya está agregado.",
            ]);
        }

        $ids[] = $item->id;
        $this->setCart($ids);

        return redirect()->route('pos.index')
            ->with('success', "{$item->codigo} agregado al carrito.");
    }

    public function remove(Request $request)
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer'],
        ]);

        $ids = array_values(array_filter(
            $this->cartIds(),
            fn (int $id) => $id !== (int) $data['item_id']
        ));

        $this->setCart($ids);

        return redirect()->route('pos.index')->with('success', 'Equipo quitado del carrito.');
    }

    /**
     * Confirmación de venta atómica.
     *
     * - Total y precio SIEMPRE se calculan en el servidor desde la BD.
     * - Cada Item se re-lee bajo FOR UPDATE (orden determinista por id).
     * - Cualquier estado no vendible o precio inválido aborta TODA la venta.
     */
    public function checkout(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'integer', 'distinct'],
            'forma_pago' => ['required', Rule::in(Venta::FORMAS_PAGO)],
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);

        $cart = $this->cartIds();

        if ($cart === []) {
            throw ValidationException::withMessages([
                'items' => 'El carrito está vacío. Escanea al menos un equipo.',
            ]);
        }

        $submitted = array_values(array_map('intval', $data['items']));
        sort($cart);
        sort($submitted);

        if ($cart !== $submitted) {
            throw ValidationException::withMessages([
                'items' => 'El carrito enviado no coincide con la sesión. Vuelve a intentarlo.',
            ]);
        }

        $ids = $this->cartIds();

        $venta = DB::transaction(function () use ($ids, $data) {
            $items = Item::query()
                ->whereIn('id', $ids)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            if ($items->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'items' => 'Uno o más equipos del carrito ya no existen.',
                ]);
            }

            // Revalidación bajo lock: solo DISPONIBLE se vende; precio real desde BD.
            $totalCentavos = 0;

            foreach ($items as $item) {
                if ($error = $this->estadoError($item)) {
                    throw ValidationException::withMessages(['items' => $error]);
                }

                if ($error = $this->precioError($item)) {
                    throw ValidationException::withMessages(['items' => $error]);
                }

                $totalCentavos += $this->precioACentavos($item->precio);
            }

            $venta = Venta::create([
                'user_id' => Auth::id(),
                'total' => $this->centavosAPrecio($totalCentavos),
                'forma_pago' => $data['forma_pago'],
                'notas' => $data['notas'] ?? null,
            ]);

            foreach ($items as $item) {
                VentaDetalle::create([
                    'venta_id' => $venta->id,
                    'item_id' => $item->id,
                    'precio' => $item->precio,
                ]);

                $item->update(['estado' => 'VENDIDO']);

                Movimiento::create([
                    'item_id' => $item->id,
                    'user_id' => Auth::id(),
                    'tipo' => Movimiento::TIPO_VENTA,
                    'de_estado' => 'DISPONIBLE',
                    'a_estado' => 'VENDIDO',
                    'de_ubicacion_id' => $item->ubicacion_id,
                    'a_ubicacion_id' => $item->ubicacion_id,
                    'notas' => 'Venta '.$venta->folio,
                    'evidencia_path' => null,
                ]);
            }

            return $venta;
        });

        $this->setCart([]);

        return redirect()->route('ventas.show', $venta)
            ->with('success', "Venta {$venta->folio} registrada correctamente.");
    }
}
