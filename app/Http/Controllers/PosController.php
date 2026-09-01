<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\PagoVenta;
use App\Models\SesionCaja;
use App\Models\Venta;
use App\Models\VentaDetalle;
use App\Services\CajaService;
use App\Services\CuentaPorCobrarService;
use App\Support\ItemCodigo;
use App\Support\Money;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    private const CART_KEY = 'pos.cart';

    private const CLIENTE_KEY = 'pos.cliente_id';

    /**
     * Máximo monetario en centavos consistente con la precisión de BD
     * decimal(12,2) → 9,999,999,999.99 = 999,999,999,999 centavos.
     */
    private const MAX_MONETARIO_CENTAVOS = 999999999999;

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

        // Snapshot informativo de crédito del cliente seleccionado (SOLO UI).
        // Nunca es autoridad: la validación final vive en CuentaPorCobrarService
        // bajo el lock del Cliente. Disponible = límite - exposición actual
        // (en centavos enteros).
        $cliente = $this->clienteSeleccionado();
        $creditoInfo = null;

        if ($cliente instanceof Cliente && $cliente->credito_habilitado) {
            $limiteCentavos = $cliente->limite_credito !== null
                ? Money::aCentavos($cliente->limite_credito)
                : 0;

            $exposicionCentavos = $cliente->credito_habilitado
                ? (int) \App\Models\CuentaPorCobrar::query()
                    ->where('cliente_id', $cliente->id)
                    ->where('saldo_centavos', '>', 0)
                    ->sum('saldo_centavos')
                : 0;

            $creditoInfo = [
                'habilitado' => $cliente->credito_habilitado,
                'limite_centavos' => $limiteCentavos,
                'dias_credito' => $cliente->dias_credito,
                'disponible_centavos' => max(0, $limiteCentavos - $exposicionCentavos),
            ];
        }

        return view('pos.index', [
            'items' => $items->values(),
            'total' => Money::aPrecio($totalCentavos),
            'metodosPago' => PagoVenta::METODOS,
            'cliente' => $cliente,
            'creditoInfo' => $creditoInfo,
            'sesionCaja' => app(CajaService::class)->sesionAbiertaDe(Auth::user()),
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
     * Normalización determinista: ItemCodigo::normalizarLectura() (trim +
     * uppercase y, para lecturas ITM, separadores equivalentes + 6 dígitos),
     * con lookup exacto por Item.codigo.
     */
    public function add(Request $request)
    {
        $codigo = ItemCodigo::normalizarLectura($request->get('codigo'));

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
     * Confirmación de venta atómica (B14 + B15.3 crédito/CxC).
     *
     * - Requiere una sesión de caja ABIERTA y asignada del usuario operador. Si
     *   no la hay la venta NO se crea (error controlado, el carrito se
     *   conserva). Esto aplica TAMBIÉN a una venta 100% crédito: la sesión es
     *   contexto operacional/auditable del POS, aunque no haya movimiento
     *   físico de efectivo (B14.3.1 intacto).
     * - Total, precios y pagos SIEMPRE se calculan/revalidan en el servidor.
     * - CRÉDITO NO vive en pagos_venta: llega por un campo SEPARADO
     *   credito_monto, normalizado a centavos SIN float vía Money::aCentavos.
     *   La cobertura debe cumplir EXACTAMENTE:
     *     pagosRealesCentavos === importeRealEsperado (= total - credito)
     * - Sin crédito se preserva B14: SIEMPRE debe enviarse al menos un pago real
     *   (B15.3 no introduce ventas gratuitas implícitas).
     * - Si credito > 0 se origina CuentaPorCobrar vía CuentaPorCobrarService
     *   (B15.2 intacto) dentro de la MISMA transacción; cualquier DomainException
     *   económica se convierte en ValidationException controlada y provoca
     *   rollback TOTAL (Venta, detalles, item, pagos, caja, CxC y ledger).
     * - ventas.forma_pago se DERIVA server-side:
     *     credito == 0            -> comportamiento B14 (único método o MIXTO)
     *     credito > 0 && real > 0 -> MIXTO
     *     credito == total        -> CREDITO
     * - Orden de locks determinista:
     *     1) Sesión de caja (lockForUpdate + revalidación ABIERTA)
     *     2) Cliente (lockForUpdate + revalidación ACTIVO; mutex de exposición)
     *     3) Items ordenados por id (lockForUpdate)
     */
    public function checkout(Request $request)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'integer', 'distinct'],
            'notas' => ['nullable', 'string', 'max:1000'],
            // B15.3: pagos reales son OPCIONALES (una venta puede ser 100% crédito),
            // pero todo pago real enviado conserva sus reglas B14.
            'pagos' => ['array'],
            'pagos.*.metodo' => ['required', Rule::in(PagoVenta::METODOS)],
            'pagos.*.monto_aplicado' => ['required', 'numeric', 'min:0.01'],
            'pagos.*.efectivo_recibido' => ['nullable', 'numeric', 'min:0'],
            'pagos.*.referencia' => ['nullable', 'string', 'max:100'],
            // CREDITO como componente de DEUDA separado, nunca dentro de pagos.
            // Barrera HTTP previa a Money: credito_monto es PESOS/una unidad
            // decimal; su máximo decimal(12,2) en esta capa es 9999999999.99,
            // antes de llegar al cast entero de Money. MAX_MONETARIO_CENTAVOS
            // se mantiene como segunda defensa tras Money::aCentavos.
            'credito_monto' => [
                'nullable',
                'numeric',
                'min:0',
                'max:9999999999.99',
            ],
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

        // Requisito B14: sesión de caja abierta. Revisión temprana (UX
        // controlada); se revalida y bloquea dentro de la transacción.
        $esionPreliminar = app(CajaService::class)->sesionAbiertaDe(Auth::user());

        if (! $esionPreliminar instanceof SesionCaja) {
            throw ValidationException::withMessages([
                'caja' => 'Debes abrir una caja antes de registrar ventas.',
            ]);
        }

        $ids = $this->cartIds();

        $venta = DB::transaction(function () use ($ids, $data, $clienteId) {
            // Lock 1: Sesión de caja. Revalidada ABIERTA bajo lock para impedir
            // ventas concurrentes entrando mientras se cierra la caja.
            $sesion = SesionCaja::query()
                ->lockForUpdate()
                ->where('user_id_apertura', Auth::id())
                ->abiertas()
                ->with('caja')
                ->first();

            if (! $sesion instanceof SesionCaja) {
                throw ValidationException::withMessages([
                    'caja' => 'Debes abrir una caja antes de registrar ventas.',
                ]);
            }

            // Lock 2: Cliente. Debe existir y estar ACTIVO en el momento de la venta.
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

            // Lock 3: Items ordenados por id.
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

            // Normalización server-side de los pagos REALES a centavos enteros.
            // Puede estar vacío en una venta 100% crédito.
            $pagosCentavos = [];

            foreach ($data['pagos'] ?? [] as $pago) {
                $metodo = $pago['metodo'];
                $montoCentavos = Money::aCentavos($pago['monto_aplicado']);

                $recibidoCentavos = null;

                if ($metodo === PagoVenta::METODO_EFECTIVO) {
                    if (($pago['efectivo_recibido'] ?? null) === null || trim((string) $pago['efectivo_recibido']) === '') {
                        throw ValidationException::withMessages([
                            'pagos' => 'El pago en efectivo requiere el efectivo recibido.',
                        ]);
                    }

                    $recibidoCentavos = Money::aCentavos($pago['efectivo_recibido']);

                    if ($recibidoCentavos < $montoCentavos) {
                        throw ValidationException::withMessages([
                            'pagos' => 'El efectivo recibido es insuficiente para el monto a aplicar.',
                        ]);
                    }
                }

                $pagosCentavos[] = [
                    'metodo' => $metodo,
                    // Se conservan como PESOS (string) para que cobrarVenta los
                    // renormalice con Money::aCentavos sin duplicar el factor 100.
                    'monto_aplicado' => Money::aPrecio($montoCentavos),
                    'efectivo_recibido' => $recibidoCentavos === null ? null : Money::aPrecio($recibidoCentavos),
                    'referencia' => trim((string) ($pago['referencia'] ?? '')) !== '' ? $pago['referencia'] : null,
                ];
            }

            // Normalización server-side del CRÉDITO (componente de DEUDA separado).
            // Política monetaria SIN float: null / '' -> 0; el resto se convierte
            // a centavos enteros con Money::aCentavos ("0", "0.0", "0.00" -> 0).
            // Un valor inválido (más de 2 decimales, no numérico, notación
            // científica como "1e2") lanza \UnexpectedValueException y se traduce
            // en ValidationException CONTROLADA (nunca un 500).
            $creditoCentavos = 0;

            $creditoBruto = $data['credito_monto'] ?? null;

            if ($creditoBruto !== null && trim((string) $creditoBruto) !== '') {
                try {
                    $creditoCentavos = Money::aCentavos($creditoBruto);
                } catch (\UnexpectedValueException) {
                    throw ValidationException::withMessages([
                        'credito_monto' => 'El monto a crédito debe ser un importe válido con máximo 2 decimales.',
                    ]);
                }
            }

            // Máximo consistente con la precisión monetaria real del proyecto/BD
            // (todas las columnas monetarias son decimal(12,2)).
            if (abs($creditoCentavos) > self::MAX_MONETARIO_CENTAVOS) {
                throw ValidationException::withMessages([
                    'credito_monto' => 'El monto a crédito excede la precisión monetaria permitida.',
                ]);
            }

            if ($creditoCentavos > $totalCentavos) {
                throw ValidationException::withMessages([
                    'credito_monto' => 'El monto a crédito no puede exceder el total de la venta.',
                ]);
            }

            // Pagos reales y su importe esperado DERIVADO de fuentes autoritativas:
            //   importeRealEsperado = total - credito
            // CobrarVenta recibe ese esperado (no el mismo dato que se valida).
            $pagosRealesCentavos = array_sum(array_map(
                fn (array $pago) => Money::aCentavos($pago['monto_aplicado']),
                $pagosCentavos
            ));

            $importeRealEsperadoCentavos = $totalCentavos - $creditoCentavos;

            // Invariante explícito: pagos reales deben ser EXACTAMENTE lo que la
            // venta espera cobrar (equivale a pagosReales + credito === total).
            if ($pagosRealesCentavos !== $importeRealEsperadoCentavos) {
                $faltante = $importeRealEsperadoCentavos - $pagosRealesCentavos;

                throw ValidationException::withMessages([
                    'pagos' => $faltante > 0
                        ? 'Los pagos (reales + crédito) no cubren el total de la venta.'
                        : 'Los pagos (reales + crédito) superan el total de la venta.',
                ]);
            }

            // Preservación B14 sin crédito: si no hay crédito, SIEMPRE debe ir al
            // menos un pago real. B15.3 no introduce ventas gratuitas implícitas,
            // ni siquiera cuando el total es 0.
            if ($creditoCentavos === 0 && $pagosCentavos === []) {
                throw ValidationException::withMessages([
                    'pagos' => 'Debe enviarse al menos un pago real cuando no hay crédito.',
                ]);
            }

            // ventas.forma_pago derivado SERVER-SIDE (B15.3), nunca del navegador:
            //   credito == 0            -> comportamiento B14 (único método real o MIXTO)
            //   credito > 0 && real > 0 -> MIXTO
            //   credito == total        -> CREDITO (0 pagos reales)
            $metodos = array_values(array_unique(array_column($pagosCentavos, 'metodo')));

            if ($creditoCentavos > 0 && $pagosRealesCentavos > 0) {
                $formaPago = 'MIXTO';
            } elseif ($creditoCentavos > 0 && $pagosRealesCentavos === 0) {
                $formaPago = 'CREDITO';
            } else {
                $formaPago = count($metodos) === 1 ? $metodos[0] : 'MIXTO';
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
                'forma_pago' => $formaPago,
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

            // B15.3 paso 18: si hay crédito, origina la CuentaPorCobrar dentro de
            // la MISMA transacción bajo el lock del Cliente (mutex de exposición).
            // CuentaPorCobrarService ejecuta sus propias validaciones y locks
            // (B15.2 intacto). Cualquier DomainException económica se convierte en
            // ValidationException CONTROLADA y provoca rollback TOTAL del checkout.
            if ($creditoCentavos > 0) {
                try {
                    app(CuentaPorCobrarService::class)->crearParaVenta(
                        $venta,
                        $creditoCentavos,
                        Auth::user()
                    );
                } catch (DomainException $e) {
                    throw ValidationException::withMessages([
                        'credito_monto' => $e->getMessage(),
                    ]);
                }
            }

            // B15.3 paso 19: cobro de los pagos REALES. El último argumento es el
            // importe REAL esperado DERIVADO (total - crédito), no el total ni el
            // mismo dato que se validó como suma de pagos. En una venta 100%
            // crédito es 0 -> no se crea PagoVenta ni MovimientoCaja. Caja física
            // SOLO ve dinero real.
            app(CajaService::class)->cobrarVenta(
                $venta,
                $sesion,
                Auth::user(),
                $pagosCentavos,
                $importeRealEsperadoCentavos
            );

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
