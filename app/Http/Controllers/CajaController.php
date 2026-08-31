<?php

namespace App\Http\Controllers;

use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\SesionCaja;
use App\Services\CajaService;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class CajaController extends Controller
{
    public function __construct(private readonly CajaService $service) {}

    /**
     * Sesiones de caja. Por defecto SOLO las propias; cajas.ver_todas
     * habilita el historial global (Admin/Auditor).
     */
    public function index()
    {
        $user = Auth::user();
        $puedeVerTodas = $user->can('cajas.ver_todas');

        $sesiones = SesionCaja::query()
            ->with(['caja', 'usuarioApertura', 'usuarioCierre'])
            ->when(! $puedeVerTodas, fn ($q) => $q->where('user_id_apertura', $user->id))
            ->latest('id')
            ->paginate(20);

        $abierta = $this->service->sesionAbiertaDe($user);

        return view('cajas.index', compact('sesiones', 'abierta', 'puedeVerTodas'));
    }

    /**
     * Formulario de apertura (cajas.abrir).
     */
    public function abrir()
    {
        if ($this->service->sesionAbiertaDe(Auth::user()) instanceof SesionCaja) {
            return redirect()->route('cajas.index')
                ->with('info', 'Ya tienes una sesión de caja abierta. Ciérrala antes de abrir otra.');
        }

        $cajas = Caja::query()->activas()->get();

        return view('cajas.abrir', ['cajas' => $cajas]);
    }

    public function abrirSesion(Request $request)
    {
        $data = $request->validate([
            'caja_id' => ['required', 'integer', 'exists:cajas,id'],
            'fondo_inicial' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'observaciones_apertura' => ['nullable', 'string', 'max:1000'],
        ]);

        $caja = Caja::findOrFail($data['caja_id']);

        try {
            $sesion = $this->service->abrirSesion(
                $caja,
                Auth::user(),
                Money::aCentavos($data['fondo_inicial']),
                $data['observaciones_apertura'] ?? null,
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['fondo_inicial' => $e->getMessage()]);
        }

        return redirect()->route('cajas.movimientos', $sesion)
            ->with('success', "Sesión {$sesion->folio} abierta en {$caja->nombre}. Ya puedes registrar ventas en caja.");
    }

    /**
     * Movimientos de una sesión (cajas.movimientos). Solo la sesión propia,
     * salvo permiso de historial global.
     */
    public function movimientos(SesionCaja $sesion)
    {
        $this->guardarSesionVisible($sesion);

        $sesion->load([
            'caja',
            'usuarioApertura',
            'usuarioCierre',
            'pagos.venta',
            'movimientos.user',
            'movimientos.pago',
            'arqueos.denominaciones',
        ]);

        $esperado = $sesion->estaAbierta() ? $this->service->calcularEfectivoEsperado($sesion) : null;
        $entradas = $sesion->movimientos->filter->esEntrada()->sum(fn ($m) => Money::aCentavos($m->monto));
        $salidas = $sesion->movimientos->filter(fn ($m) => $m->esSalida() && $m->tipo !== 'CAMBIO_ENTREGADO')
            ->sum(fn ($m) => Money::aCentavos($m->monto));

        return view('cajas.movimientos', compact('sesion', 'esperado', 'entradas', 'salidas'));
    }

    public function entrada(Request $request, SesionCaja $sesion)
    {
        $this->guardarSesionAbierta($sesion);

        $data = $request->validate([
            'monto' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'concepto' => ['required', 'string', 'max:255'],
            'referencia' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $movimiento = $this->service->registrarEntradaManual(
                $sesion,
                Auth::user(),
                Money::aCentavos($data['monto']),
                $data['concepto'],
                $data['referencia'] ?? null,
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['concepto' => $e->getMessage()]);
        }

        return redirect()->route('cajas.movimientos', $sesion)
            ->with('success', 'Entrada registrada: '.$movimiento->concepto);
    }

    public function retiro(Request $request, SesionCaja $sesion)
    {
        $this->guardarSesionAbierta($sesion);

        $data = $request->validate([
            'monto' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'motivo' => ['required', 'string', 'max:255'],
            'referencia' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $movimiento = $this->service->registrarRetiro(
                $sesion,
                Auth::user(),
                Money::aCentavos($data['monto']),
                $data['motivo'],
                $data['referencia'] ?? null,
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['motivo' => $e->getMessage()]);
        }

        return redirect()->route('cajas.movimientos', $sesion)
            ->with('success', 'Retiro registrado: '.$movimiento->concepto);
    }

    /**
     * Ajuste administrativo (Admin-only, cajas.ajustar). Corrige el cajón con
     * operación auditable e inmutable; nunca modifica movimientos anteriores.
     */
    public function ajuste(Request $request, SesionCaja $sesion)
    {
        $this->guardarSesionAbierta($sesion);

        $data = $request->validate([
            'monto' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'direccion' => ['required', Rule::in([MovimientoCaja::DIR_ENTRADA, MovimientoCaja::DIR_SALIDA])],
            'motivo' => ['required', 'string', 'max:255'],
            'referencia' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $movimiento = $this->service->registrarAjuste(
                $sesion,
                Auth::user(),
                Money::aCentavos($data['monto']),
                $data['direccion'],
                $data['motivo'],
                $data['referencia'] ?? null,
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['motivo' => $e->getMessage()]);
        }

        return redirect()->route('cajas.movimientos', $sesion)
            ->with('success', 'Ajuste registrado ('.$movimiento->direccion.'): '.$movimiento->concepto);
    }

    /**
     * Arqueo final bajo corte ciego (cajas.cerrar). El formulario NO muestra
     * el efectivo esperado: se contabiliza por denominaciones y el sistema
     * calcula la diferencia a la hora de cerrar.
     */
    public function cerrar(SesionCaja $sesion)
    {
        $this->guardarSesionOperable($sesion);

        return view('cajas.cerrar', [
            'sesion' => $sesion,
            'denominaciones' => CajaService::DENOMINACIONES,
        ]);
    }

    public function cerrarSesion(Request $request, SesionCaja $sesion)
    {
        $this->guardarSesionOperable($sesion);

        $data = $request->validate([
            'denominaciones' => ['required', 'array'],
            'denominaciones.*' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'observaciones_cierre' => ['nullable', 'string', 'max:2000'],
        ]);

        // Normalización server-side: solos las denominaciones del canon.
        $contado = [];
        $contadoCentavos = 0;

        foreach (CajaService::DENOMINACIONES as $den) {
            $clave = (string) $den;
            $cantidad = isset($data['denominaciones'][$clave])
                ? (int) $data['denominaciones'][$clave]
                : 0;

            $contado[$den] = $cantidad;
            $contadoCentavos += Money::aCentavos((string) $den) * $cantidad;
        }

        try {
            $cerrada = $this->service->cerrarSesion(
                $sesion,
                Auth::user(),
                $contado,
                $contadoCentavos,
                $data['observaciones_cierre'] ?? null,
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages([
                'observaciones_cierre' => $e->getMessage(),
            ]);
        }

        return redirect()->route('cajas.movimientos', $cerrada)
            ->with('success', "Sesión {$cerrada->folio} cerrada correctamente.");
    }

    /**
     * Ver (consultar): propia o con cajas.ver_todas (Admin/Auditor).
     */
    private function guardarSesionVisible(SesionCaja $sesion): void
    {
        if ($sesion->user_id_apertura !== Auth::id() && ! Auth::user()->can('cajas.ver_todas')) {
            abort(403, 'No tienes permiso para consultar esta sesión de caja.');
        }
    }

    /**
     * Registrar escritura sobre una sesión ABIERTA (entrada/retiro/ajuste).
     * Exige que la sesión esté abierta y que el usuario pueda verla (dueño o
     * cajas.ver_todas). El permiso de escritura específico lo garantiza el
     * middleware (cajas.entrada / cajas.retiro / cajas.ajustar).
     */
    private function guardarSesionAbierta(SesionCaja $sesion): void
    {
        if ($sesion->user_id_apertura !== Auth::id() && ! Auth::user()->can('cajas.ver_todas')) {
            abort(403, 'No tienes permiso para operar esta sesión de caja.');
        }

        if (! $sesion->estaAbierta()) {
            abort(409, 'La sesión está cerrada; no puede registrarse esta operación.');
        }
    }

    /**
     * Operar cierre (cajas.cerrar): solo la sesión propia y ABIERTA.
     */
    private function guardarSesionOperable(SesionCaja $sesion): void
    {
        if ($sesion->user_id_apertura !== Auth::id()) {
            abort(403, 'Solo el operador que abrió la sesión puede cerrarla.');
        }

        if (! $sesion->estaAbierta()) {
            abort(409, 'La sesión ya está cerrada.');
        }
    }

    /**
     * Corte de caja (cajas.movimientos / cajas.ver): datos consolidados.
     */
    private function datosCorte(SesionCaja $sesion): array
    {
        $this->guardarSesionVisible($sesion);

        // El corte solo existe después del cierre. Esto evita revelar por URL
        // directa el efectivo esperado de una sesión todavía abierta.
        if ($sesion->estaAbierta()) {
            abort(409, 'El corte solo está disponible para sesiones cerradas.');
        }

        return $this->service->datosCorte($sesion);
    }

    /**
     * Consulta WEB de un corte ya cerrado.
     */
    public function corte(SesionCaja $sesion)
    {
        $datos = $this->datosCorte($sesion);

        return view('cajas.corte', [
            'd' => $datos,
            'sesion' => $sesion,
            'modoImpresion' => false,
        ]);
    }

    /**
     * Versión HTML limpia para reimpresión desde navegador.
     */
    public function corteImprimir(SesionCaja $sesion)
    {
        $datos = $this->datosCorte($sesion);

        return view('cajas.corte', [
            'd' => $datos,
            'sesion' => $sesion,
            'modoImpresion' => true,
        ]);
    }

    public function cortePdf(SesionCaja $sesion)
    {
        $datos = $this->datosCorte($sesion);

        $pdf = Pdf::loadView('cajas.pdf.corte', ['d' => $datos])
            ->setPaper('a4')
            ->setOptions([
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => true,
                'dpi' => 96,
                'defaultFont' => 'DejaVu Sans',
            ]);

        return $pdf->download('corte_caja_'.$sesion->folio.'.pdf');
    }

    public function corteXlsx(SesionCaja $sesion)
    {
        $datos = $this->datosCorte($sesion);

        return Excel::download(new \App\Exports\CorteCajaExport($datos), 'corte_caja_'.$sesion->folio.'.xlsx');
    }
}
