<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    private const CART_KEY = 'pos.cart';

    private const CLIENTE_KEY = 'pos.cliente_id';

    private function cartIds(): array
    {
        return array_values(array_map('intval', session(self::CART_KEY, [])));
    }

    private function setCart(array $ids): void
    {
        session([self::CART_KEY => array_values(array_map('intval', $ids))]);
    }

    /**
     * Cliente seleccionado en la sesión del POS (si existe y sigue activo).
     * Nunca se confía en el navegador para el cliente de una venta: la fuente
     * de verdad al confirmar es la BD, revalidada bajo lock dentro del checkout.
     */
    public function clienteSeleccionado(): ?Cliente
    {
        $id = (int) session(self::CLIENTE_KEY, 0);

        if ($id <= 0) {
            return null;
        }

        return Cliente::query()->activos()->find($id);
    }

    private function estadoError(Item $item): ?string
    {
        return match ($item->estado) {
            'DISPONIBLE' => null,
            'RESERVADO' => "El equipo {$item->codigo} está RESERVADO y no puede venderse desde POS.",
            'REPARACION' => "El equipo {$item->codigo} está en REPARACIÓN y no puede venderse desde POS.",
            'VENDIDO' => "El equipo {$item->codigo} ya fue vendido.",
            'BAJA' => "El equipo {$item->codigo} está dado de baja.",
            default => "El equipo {$item->codigo} no se encuentra en un estado vendible.",
        };
    }

    private function precioError(Item $item): ?string
    {
        if ($item->precio === null || (string) $item->precio === '') {
            return "El equipo {$item->codigo} no tiene un precio válido asignado.";
        }

        try {
            if (Money::aCentavos($item->precio) < 0) {
                return "El equipo {$item->codigo} no tiene un precio válido asignado.";
            }
        } catch (\UnexpectedValueException) {
            return "El equipo {$item->codigo} no tiene un precio válido asignado.";
        }

        return null;
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
                return Money::aCentavos($item->precio);
            } catch (\UnexpectedValueException) {
                return 0;
            }
        });

        return view('pos.index', [
            'items' => $items->values(),
            'total' => Money::aPrecio($totalCentavos),
            'formasPago' => Venta::FORMAS_PAGO,
            'cliente' => $this->clienteSeleccionado(),
        ]);
    }

    /**
     * Selecciona un cliente para la venta (lo guarda en sesión).
     * Se revalida de nuevo en el checkout bajo lock.
     */
    public function setCliente(Request $request)
    {
        $data = $request->validate([
            'cliente_id' => ['required', 'integer'],
        ]);

        $cliente = Cliente::query()->activos()->find((int) $data['cliente_id']);

        if (! $cliente instanceof Cliente) {
            throw ValidationException::withMessages([
                'cliente_id' => 'Cliente no encontrado o inactivo. Selecciona otro.',
            ]);
        }

        session([self::CLIENTE_KEY => $cliente->id]);

        return redirect()->route('pos.index')->with('success', "Cliente {$cliente->codigo} seleccionado.");
    }

    /**
     * Quita el cliente seleccionado del carrito (cambiar cliente).
     */
    public function clearCliente()
    {
        session([self::CLIENTE_KEY => null]);

        return redirect()->route('pos.index')->with('success', 'Cliente sin seleccionar.');
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
     * - Cliente REQUERIDO: se revalida bajo lock dentro de la transacción.
     * - Orden de locks determinista para evitar deadlocks:
     *     1) Cliente (lockForUpdate)
     *     2) Items ordenados por id (lockForUpdate)
     *   El postventa bloquea Venta->Detalle->Item; como aquí Venta recién se
     *   INSERTA (no se bloquea fila previa) no hay ciclo de deadlock.
     * - El snapshot del cliente se copia server-side desde el Cliente bloqueado.
     */
    public function checkout(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'integer', 'distinct'],
            'forma_pago' => ['required', Rule::in(Venta::FORMAS_PAGO)],
            'notas' => ['nullable', 'string', 'max:1000'],
        ]);

        $clienteId = (int) session(self::CLIENTE_KEY, 0);

        if ($clienteId <= 0) {
            throw ValidationException::withMessages([
                'cliente_id' => 'Selecciona un cliente para registrar la venta.',
            ]);
        }

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

        $venta = DB::transaction(function () use ($ids, $data, $clienteId) {
            // Lock 1: Cliente. Debe existir y estar ACTIVO en el momento de la venta.
            $cliente = Cliente::query()->lockForUpdate()->find($clienteId);

            if (! $cliente instanceof Cliente) {
                throw ValidationException::withMessages([
                    'cliente_id' => 'El cliente seleccionado ya no existe.',
                ]);
            }

            if (! $cliente->activo) {
                throw ValidationException::withMessages([
                    'cliente_id' => "El cliente {$cliente->codigo} está inactivo y no puede usarse en una venta.",
                ]);
            }

            // Lock 2: Items ordenados por id.
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

                $totalCentavos += Money::aCentavos($item->precio);
            }

            // Snapshot del cliente AL MOMENTO de la venta (server-side, desde el
            // Cliente bloqueado). NO se reescribe posteriormente ni al editar el Cliente.
            $venta = Venta::create([
                'user_id' => Auth::id(),
                'cliente_id' => $cliente->id,
                'cliente_codigo' => $cliente->codigo,
                'cliente_nombre' => $cliente->nombre,
                'cliente_rfc' => $cliente->rfc,
                'cliente_telefono' => $cliente->telefono,
                'cliente_email' => $cliente->email,
                'cliente_tipo' => $cliente->tipo,
                'total' => Money::aPrecio($totalCentavos),
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
        session([self::CLIENTE_KEY => null]);

        // Autoprint: si está habilitado (y es seguro intentarlo), se abre el
        // ticket para que una estación con --kiosk-printing lo imprima
        // automáticamente al cargar. En un navegador normal muestra el diálogo.
        if (Configuracion::ticketAutoprint()) {
            return redirect()
                ->route('ventas.ticket', ['venta' => $venta, 'autoprint' => 1])
                ->with('success', "Venta {$venta->folio} registrada.");
        }

        return redirect()->route('ventas.show', $venta)
            ->with('success', "Venta {$venta->folio} registrada correctamente.");
    }
}
