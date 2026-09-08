<?php

namespace App\Http\Controllers;

use App\Models\CuentaPorCobrar;
use App\Models\MovimientoCxC;
use App\Services\CajaService;
use App\Services\CuentaPorCobrarService;
use App\Support\CxCAcceso;
use App\Support\Money;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cobranza y abonos de Cuentas por Cobrar (B15.4).
 *
 * El Controller se limita a:
 *  - validación HTTP / Money parser
 *  - autorización (middleware + @can en vistas)
 *  - traducción de DomainException del servicio a ValidationException controlada
 *  - redirects y mensajes UX
 *
 * La lógica económica (locks, invariantes, estado, ledger, caja, idempotencia)
 * vive en CuentaPorCobrarService. NO se captura Throwable genérico: los errores
 * de infraestructura deben propagarse.
 */
class CuentaPorCobrarController extends Controller
{
    public function __construct(
        private readonly CuentaPorCobrarService $cxcService,
        private readonly CajaService $cajaService
    ) {}

    public function index(Request $request)
    {
        $filtros = [
            'folio' => trim((string) $request->query('folio', '')),
            'cliente' => trim((string) $request->query('cliente', '')),
            'estado' => $request->query('estado') ?: null,
            'vencidas' => $request->query('vencidas') ?: null,
            'con_saldo' => $request->query('con_saldo') ?: null,
        ];

        // Solo lectura: la CuentaPorCobrar es histórica, nunca se filtra por
        // un estado inventado en el browser. 'vencidas' y 'con_saldo' son
        // derivados de saldo/fecha, no estados persistidos.
        $cuentas = CuentaPorCobrar::query()
            ->with('cliente', 'venta')
            ->when($filtros['folio'] !== '', fn ($q) => $q->where('folio', 'ilike', "%{$filtros['folio']}%"))
            ->when($filtros['cliente'] !== '', function ($q) use ($filtros) {
                $q->where(function ($w) use ($filtros) {
                    $w->whereHas('cliente', fn ($c) => $c->where('nombre', 'ilike', "%{$filtros['cliente']}%"))
                        ->orWhereHas('cliente', fn ($c) => $c->where('codigo', 'ilike', "%{$filtros['cliente']}%"));
                });
            })
            ->when(
                $filtros['estado'],
                fn ($q) => $q->where('estado', $filtros['estado'])
            )
            ->when($filtros['vencidas'], function ($q) {
                $q->where('saldo_centavos', '>', 0)
                    ->where('fecha_vencimiento', '<', now()->startOfDay()->toDateString())
                    ->where('estado', '!=', CuentaPorCobrar::ESTADO_CANCELADA);
            })
            ->when($filtros['con_saldo'], fn ($q) => $q->where('saldo_centavos', '>', 0))
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        $hoy = now()->startOfDay()->toDateString();

        $saldoTotalCentavos = (int) CuentaPorCobrar::query()->sum('saldo_centavos');
        $saldoVencidoCentavos = (int) CuentaPorCobrar::query()
            ->where('saldo_centavos', '>', 0)
            ->where('fecha_vencimiento', '<', $hoy)
            ->where('estado', '!=', CuentaPorCobrar::ESTADO_CANCELADA)
            ->sum('saldo_centavos');
        $cuentasActivas = CuentaPorCobrar::query()
            ->where('saldo_centavos', '>', 0)
            ->count();

        return view('cxc.index', [
            'cuentas' => $cuentas,
            'filtros' => $filtros,
            'estados' => CuentaPorCobrar::ESTADOS,
            'saldoTotal' => Money::formatear(Money::aPrecio($saldoTotalCentavos)),
            'saldoVencido' => Money::formatear(Money::aPrecio($saldoVencidoCentavos)),
            'cuentasActivas' => $cuentasActivas,
        ]);
    }

    public function show(CuentaPorCobrar $cuenta)
    {
        $cuenta->load([
            'cliente',
            'venta',
            'movimientos.user',
            'movimientos.movimientoOrigen',
            'movimientos.reversa',
            'movimientos.documentoPostventa',
        ]);

        $sesionAbierta = $this->cajaService->sesionAbiertaDe(Auth::user());

        return view('cxc.show', [
            'cuenta' => $cuenta,
            'movimientos' => $cuenta->movimientos,
            'sesionAbierta' => $sesionAbierta,
        ]);
    }

    /**
     * Registrar un ABONO. El servidor resuelve la sesión de caja del usuario
     * autenticado (nunca se acepta sesion_caja_id del navegador).
     */
    public function storeAbono(CuentaPorCobrar $cuenta, Request $request)
    {
        $data = $request->validate([
            'monto' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'metodo' => ['required', Rule::in(MovimientoCxC::METODOS)],
            'referencia' => ['nullable', 'string', 'max:100'],
            'observaciones' => ['nullable', 'string', 'max:500'],
            'operacion_uuid' => ['required', 'uuid'],
        ]);

        try {
            $montoCentavos = Money::aCentavos($data['monto']);
        } catch (\UnexpectedValueException) {
            throw ValidationException::withMessages([
                'monto' => 'El monto del abono debe ser un importe válido con máximo 2 decimales.',
            ]);
        }

        $referencia = trim((string) ($data['referencia'] ?? ''));

        if ($data['metodo'] !== MovimientoCxC::METODO_EFECTIVO && $referencia === '') {
            throw ValidationException::withMessages([
                'referencia' => 'El abono con '.mb_strtolower($data['metodo']).' requiere una referencia.',
            ]);
        }

        $observaciones = trim((string) ($data['observaciones'] ?? ''));

        try {
            $this->cxcService->registrarAbono(
                $cuenta,
                $montoCentavos,
                $data['metodo'],
                Auth::user(),
                $referencia !== '' ? $referencia : null,
                $observaciones !== '' ? $observaciones : null,
                $data['operacion_uuid']
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages([
                'monto' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('cxc.show', $cuenta)
            ->with('success', 'Abono registrado correctamente.');
    }

    /**
     * Reversar un ABONO erróneo (Admin-only). El {movimiento} debe pertenecer
     * realmente a {cuenta}; se valida antes de invocar el dominio.
     *
     * REV1 (FIX 1): autoridad real del endpoint. El middleware
     * `permission:cxc.reversar_abono` es la PRIMERA barrera; aquí se revalida
     * que el usuario cumpla el invariante completo: rol Admin + permiso
     * cxc.reversar_abono (CxCAcceso::puedeReversar). Tener solo el permiso
     * directo (sin rol Admin) NO es suficiente para reversar.
     */
    public function reversarAbono(CuentaPorCobrar $cuenta, MovimientoCxC $movimiento, Request $request)
    {
        if (! CxCAcceso::puedeReversar(Auth::user())) {
            abort(403);
        }

        if ((int) $movimiento->cuenta_por_cobrar_id !== (int) $cuenta->id) {
            abort(404);
        }

        $data = $request->validate([
            'motivo' => ['required', 'string', 'max:500'],
        ]);

        try {
            $this->cxcService->reversarAbono(
                $cuenta,
                $movimiento,
                Auth::user(),
                $data['motivo']
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages([
                'motivo' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('cxc.show', $cuenta)
            ->with('success', 'Abono reversado correctamente.');
    }
}
