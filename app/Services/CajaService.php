<?php

namespace App\Services;

use App\Models\ArqueoCaja;
use App\Models\ArqueoDenominacion;
use App\Models\Caja;
use App\Models\MovimientoCaja;
use App\Models\PagoVenta;
use App\Models\SesionCaja;
use App\Models\User;
use App\Models\Venta;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Orquestador de dominio de Caja B14.
 *
 * Convención de signos (ÚNICA): monto siempre positivo; la dirección la
 * determina el tipo. Entradas: COBRO_EFECTIVO, ENTRADA_MANUAL. Salidas:
 * CAMBIO_ENTREGADO, RETIRO, REEMBOLSO_EFECTIVO. AJUSTE es reservado y
 * queda fuera del cálculo de esperado hasta que tenga dirección explícita.
 *
 * EFECTIVO ESPERADO =
 *   fondo inicial
 * + COBROS EFECTIVO (efectivo recibido)
 * - CAMBIOS entregados
 * + ENTRADAS MANUALES
 * - RETIROS
 * - REEMBOLSOS EFECTIVO
 */
class CajaService
{
    /** Denominaciones MXN aceptadas en el arqueo. */
    public const DENOMINACIONES = [1000, 500, 200, 100, 50, 20, 10, 5, 2, 1, 0.50];

    public function sesionAbiertaDe(User $user): ?SesionCaja
    {
        return SesionCaja::query()
            ->with('caja')
            ->where('user_id_apertura', $user->id)
            ->abiertas()
            ->first();
    }

    /**
     * Apertura de sesión: una caja física no puede tener dos ABIERTAS
     * simultáneas y un operador tampoco. Refuerzo en BD (índices únicos
     * parciales) y protección server-side con lockForUpdate sobre la caja.
     */
    public function abrirSesion(Caja $caja, User $user, int $fondoCentavos, ?string $observaciones = null): SesionCaja
    {
        if (! $caja->activa) {
            throw new DomainException("La caja {$caja->nombre} está inactiva y no puede abrirse.");
        }

        if ($fondoCentavos < 0) {
            throw new DomainException('El fondo inicial no puede ser negativo.');
        }

        return DB::transaction(function () use ($caja, $user, $fondoCentavos, $observaciones) {
            $caja = Caja::query()->lockForUpdate()->findOrFail($caja->id);

            // Revalidar bajo lock: la instancia exterior puede ser STALE. Una
            // desactivación concurrente ya habría hecho commit; la fila
            // bloqueada es la fuente de verdad, no el objeto pasado por el caller.
            if (! $caja->activa) {
                throw new DomainException("La caja {$caja->nombre} está inactiva y no puede abrirse.");
            }

            if (SesionCaja::query()->where('caja_id', $caja->id)->abiertas()->exists()) {
                throw new DomainException("La caja {$caja->nombre} ya tiene una sesión abierta.");
            }

            if (SesionCaja::query()->where('user_id_apertura', $user->id)->abiertas()->exists()) {
                throw new DomainException('Ya tienes una sesión de caja abierta. Ciérrala antes de abrir otra.');
            }

            return SesionCaja::create([
                'caja_id' => $caja->id,
                'user_id_apertura' => $user->id,
                'fondo_inicial' => Money::aPrecio($fondoCentavos),
                'estado' => SesionCaja::ESTADO_ABIERTA,
                'observaciones_apertura' => $observaciones,
            ]);
        });
    }

    /**
     * Registra los pagos de una venta ya persistida (debe ejecutarse DENTRO
     * de la transacción del checkout, con la sesión ya bloqueada). Valida que
     * la suma de pagos cubra exactamente el total (crédito deshabilitado) y
     * crea los movimientos físicos de efectivo (cobro + cambio).
     *
     * @param  array<int, array{metodo: string, monto_aplicado: int|string, efectivo_recibido: int|string|null, referencia: string|null}>  $pagosCentavos
     * @return array{pagos: \Illuminate\Support\Collection, movimientos: \Illuminate\Support\Collection}
     */
    public function cobrarVenta(Venta $venta, SesionCaja $sesion, User $user, array $pagosCentavos, int $totalCentavos): array
    {
        if (! $sesion->estaAbierta()) {
            throw new DomainException('La sesión de caja ya fue cerrada; no puede registrarse un cobro.');
        }

        $aplicadoCentavos = 0;

        foreach ($pagosCentavos as $pago) {
            $metodo = $pago['metodo'];
            $montoCentavos = Money::aCentavos($pago['monto_aplicado']);

            if (! in_array($metodo, PagoVenta::METODOS, true)) {
                throw new DomainException("El método de pago {$metodo} no está habilitado.");
            }

            if ($montoCentavos <= 0) {
                throw new DomainException('Cada pago debe aplicar un monto mayor a cero.');
            }

            if ($metodo === PagoVenta::METODO_EFECTIVO) {
                if (($pago['efectivo_recibido'] ?? null) === null || trim((string) $pago['efectivo_recibido']) === '') {
                    throw new DomainException('El pago en efectivo requiere el efectivo recibido.');
                }

                $recibidoCentavos = Money::aCentavos($pago['efectivo_recibido']);

                if ($recibidoCentavos < $montoCentavos) {
                    throw new DomainException('El efectivo recibido es insuficiente para el monto a aplicar.');
                }
            }

            $aplicadoCentavos += $montoCentavos;
        }

        if ($aplicadoCentavos !== $totalCentavos) {
            $faltante = $totalCentavos - $aplicadoCentavos;

            throw new DomainException(
                $faltante > 0
                    ? 'Los pagos no cubren el total de la venta. Revísalo e inténtalo de nuevo.'
                    : 'Los pagos superan el total de la venta. Revísalo e inténtalo de nuevo.'
            );
        }

        $pagos = collect();
        $movimientos = collect();

        $orden = 1;

        foreach ($pagosCentavos as $pago) {
            $metodo = $pago['metodo'];
            $montoCentavos = Money::aCentavos($pago['monto_aplicado']);
            $recibidoCentavos = $metodo === PagoVenta::METODO_EFECTIVO
                ? Money::aCentavos($pago['efectivo_recibido'])
                : null;
            $cambioCentavos = $metodo === PagoVenta::METODO_EFECTIVO
                ? $recibidoCentavos - $montoCentavos
                : null;

            $pagoVenta = PagoVenta::create([
                'venta_id' => $venta->id,
                'sesion_caja_id' => $sesion->id,
                'user_id' => $user->id,
                'metodo' => $metodo,
                'monto_aplicado' => Money::aPrecio($montoCentavos),
                'efectivo_recibido' => $recibidoCentavos === null ? null : Money::aPrecio($recibidoCentavos),
                'cambio_entregado' => $cambioCentavos === null ? null : Money::aPrecio($cambioCentavos),
                'referencia' => $pago['referencia'] ?? null,
                'origen' => PagoVenta::ORIGEN_POS,
                'orden' => $orden++,
            ]);

            $pagos->push($pagoVenta);

            if ($metodo !== PagoVenta::METODO_EFECTIVO) {
                continue;
            }

            $movimientos->push(MovimientoCaja::create([
                'sesion_caja_id' => $sesion->id,
                'user_id' => $user->id,
                'tipo' => MovimientoCaja::TIPO_COBRO_EFECTIVO,
                'direccion' => MovimientoCaja::DIR_ENTRADA,
                'monto' => Money::aPrecio($recibidoCentavos),
                'venta_id' => $venta->id,
                'pago_venta_id' => $pagoVenta->id,
                'concepto' => "Cobro en efectivo {$venta->folio}",
            ]));

            if ($cambioCentavos > 0) {
                $movimientos->push(MovimientoCaja::create([
                    'sesion_caja_id' => $sesion->id,
                    'user_id' => $user->id,
                    'tipo' => MovimientoCaja::TIPO_CAMBIO_ENTREGADO,
                    'direccion' => MovimientoCaja::DIR_SALIDA,
                    'monto' => Money::aPrecio($cambioCentavos),
                    'venta_id' => $venta->id,
                    'pago_venta_id' => $pagoVenta->id,
                    'concepto' => "Cambio entregado {$venta->folio}",
                ]));
            }
        }

        return ['pagos' => $pagos, 'movimientos' => $movimientos];
    }

    /**
     * Efectivo esperado en el cajón (centavos enteros). NO se calcula con
     * SUM(ventas.total): tarjetas, transferencias, combinados, cambio y
     * devoluciones lo harían incorrecto. Es estrictamente el saldo físico.
     *
     * Cada movimiento suma o resta según su DIRECCIÓN explícita:
     *   ENTRADA  = + (COBRO_EFECTIVO, ENTRADA_MANUAL, AJUSTE-ENTRADA)
     *   SALIDA   = - (CAMBIO_ENTREGADO, RETIRO, REEMBOLSO_EFECTIVO, AJUSTE-SALIDA)
     */
    public function calcularEfectivoEsperado(SesionCaja $sesion): int
    {
        $sesion->load('movimientos');

        $esperado = Money::aCentavos($sesion->fondo_inicial);

        foreach ($sesion->movimientos as $movimiento) {
            $centavos = Money::aCentavos($movimiento->monto);

            if ($movimiento->esEntrada()) {
                $esperado += $centavos;
            } elseif ($movimiento->esSalida()) {
                $esperado -= $centavos;
            }
        }

        return $esperado;
    }

    public function registrarEntradaManual(SesionCaja $sesion, User $user, int $montoCentavos, string $concepto, ?string $referencia = null): MovimientoCaja
    {
        if (! $sesion->estaAbierta()) {
            throw new DomainException('La sesión de caja está cerrada; no puede registrarse la entrada.');
        }

        if ($montoCentavos <= 0) {
            throw new DomainException('El monto de la entrada debe ser mayor a cero.');
        }

        $concepto = trim($concepto);

        if ($concepto === '') {
            throw new DomainException('La entrada requiere un concepto.');
        }

        return MovimientoCaja::create([
            'sesion_caja_id' => $sesion->id,
            'user_id' => $user->id,
            'tipo' => MovimientoCaja::TIPO_ENTRADA_MANUAL,
            'direccion' => MovimientoCaja::DIR_ENTRADA,
            'monto' => Money::aPrecio($montoCentavos),
            'concepto' => $concepto,
            'referencia' => $referencia,
        ]);
    }

    public function registrarRetiro(SesionCaja $sesion, User $user, int $montoCentavos, string $motivo, ?string $referencia = null): MovimientoCaja
    {
        if (! $sesion->estaAbierta()) {
            throw new DomainException('La sesión de caja está cerrada; no puede registrarse el retiro.');
        }

        if ($montoCentavos <= 0) {
            throw new DomainException('El monto del retiro debe ser mayor a cero.');
        }

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new DomainException('El retiro requiere un motivo.');
        }

        if ($montoCentavos > $this->calcularEfectivoEsperado($sesion)) {
            throw new DomainException('El retiro supera el efectivo esperado disponible en caja.');
        }

        return MovimientoCaja::create([
            'sesion_caja_id' => $sesion->id,
            'user_id' => $user->id,
            'tipo' => MovimientoCaja::TIPO_RETIRO,
            'direccion' => MovimientoCaja::DIR_SALIDA,
            'monto' => Money::aPrecio($montoCentavos),
            'concepto' => $motivo,
            'referencia' => $referencia,
        ]);
    }

    /**
     * Registra un reembolso en efectivo por una operación postventa
     * (devolución o cancelación). Necesita sesión abierta; solo efectivo toca
     * el cajón físico.
     */
    public function registrarReembolsoEfectivo(SesionCaja $sesion, User $user, int $montoCentavos, array $contexto, string $referencia): MovimientoCaja
    {
        if (! $sesion->estaAbierta()) {
            throw new DomainException('Debes abrir una caja para realizar un reembolso en efectivo.');
        }

        if ($montoCentavos <= 0) {
            throw new DomainException('El monto del reembolso debe ser mayor a cero.');
        }

        return MovimientoCaja::create(array_merge([
            'sesion_caja_id' => $sesion->id,
            'user_id' => $user->id,
            'tipo' => MovimientoCaja::TIPO_REEMBOLSO_EFECTIVO,
            'direccion' => MovimientoCaja::DIR_SALIDA,
            'monto' => Money::aPrecio($montoCentavos),
            'concepto' => $referencia,
        ], $contexto));
    }

    /**
     * Ajuste administrativo inmutable (Admin-only). Permite corregir el cajón
     * con una operación auditable, sin modificar movimientos anteriores.
     *
     * Requiere:
     *  - sesión ABIERTA (no se ajusta una sesión cerrada)
     *  - dirección ENTRADA o SALIDA
     *  - monto > 0
     *  - motivo obligatorio
     *  - un AJUSTE de SALIDA no puede dejar el efectivo esperado negativo
     */
    public function registrarAjuste(SesionCaja $sesion, User $user, int $montoCentavos, string $direccion, string $motivo, ?string $referencia = null): MovimientoCaja
    {
        if (! $sesion->estaAbierta()) {
            throw new DomainException('La sesión de caja está cerrada; no puede registrarse un ajuste.');
        }

        if ($montoCentavos <= 0) {
            throw new DomainException('El monto del ajuste debe ser mayor a cero.');
        }

        if (! in_array($direccion, [MovimientoCaja::DIR_ENTRADA, MovimientoCaja::DIR_SALIDA], true)) {
            throw new DomainException('La dirección del ajuste debe ser ENTRADA o SALIDA.');
        }

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new DomainException('El ajuste requiere un motivo.');
        }

        if ($direccion === MovimientoCaja::DIR_SALIDA && $montoCentavos > $this->calcularEfectivoEsperado($sesion)) {
            throw new DomainException('El ajuste de salida supera el efectivo esperado disponible en caja.');
        }

        return MovimientoCaja::create([
            'sesion_caja_id' => $sesion->id,
            'user_id' => $user->id,
            'tipo' => MovimientoCaja::TIPO_AJUSTE,
            'direccion' => $direccion,
            'monto' => Money::aPrecio($montoCentavos),
            'concepto' => $motivo,
            'referencia' => $referencia,
        ]);
    }

    /**
     * Arqueo final bajo corte ciego. El efectivo_contado se recalcula
     * server-side desde las denominaciones; el operador captura sin conocer
     * el esperado. Un único arqueo FINAL por sesión (índice parcial + lock).
     */
    public function registrarArqueo(SesionCaja $sesion, User $user, array $denominaciones): ArqueoCaja
    {
        if (! $sesion->estaAbierta()) {
            throw new DomainException('La sesión de caja está cerrada; no puede capturarse un arqueo.');
        }

        $totalCentavos = 0;
        $limpios = [];

        foreach ($denominaciones as $denominacion => $cantidad) {
            $den = (float) $denominacion;
            $cant = (int) $cantidad;

            if ($cant < 0) {
                throw new DomainException('Las cantidades del arqueo no pueden ser negativas.');
            }

            if ($cant === 0) {
                continue;
            }

            if (! in_array($den, self::DENOMINACIONES, false)) {
                throw new DomainException("Denominación {$den} no válida en el arqueo.");
            }

            $subtotalCentavos = Money::aCentavos((string) $den) * $cant;
            $totalCentavos += $subtotalCentavos;
            $limpios[$den] = ['denominacion' => Money::aPrecio(Money::aCentavos((string) $den)), 'cantidad' => $cant, 'subtotal_centavos' => $subtotalCentavos];
        }

        return DB::transaction(function () use ($sesion, $user, $totalCentavos, $limpios) {
            $sesion = SesionCaja::query()->lockForUpdate()->findOrFail($sesion->id);

            if (! $sesion->estaAbierta()) {
                throw new DomainException('La sesión de caja está cerrada; no puede capturarse un arqueo.');
            }

            krsort($limpios);

            $arqueo = ArqueoCaja::create([
                'sesion_caja_id' => $sesion->id,
                'user_id' => $user->id,
                'tipo' => ArqueoCaja::TIPO_FINAL,
                'efectivo_contado' => Money::aPrecio($totalCentavos),
            ]);

            foreach ($limpios as $den => $datos) {
                ArqueoDenominacion::create([
                    'arqueo_id' => $arqueo->id,
                    'denominacion' => $datos['denominacion'],
                    'cantidad' => $datos['cantidad'],
                    'subtotal' => Money::aPrecio($datos['subtotal_centavos']),
                ]);
            }

            return $arqueo->fresh('denominaciones');
        });
    }

    /**
     * Cierre de sesión inmutable: ABIERTA → CERRADA. Dentro de una única
     * transacción con lockForUpdate sobre la sesión: captura el arqueo final,
     * calcula el esperado, la diferencia (contado - esperado) y cierra.
     * Una sesión cerrada no puede recibir ventas ni movimientos (revalidado
     * bajo lock en cobro/entrada/retiro/reembolso y en el propio checkout).
     */
    public function cerrarSesion(SesionCaja $sesion, User $usuarioCierre, array $denominaciones, int $contadoCentavos, ?string $observaciones = null): SesionCaja
    {
        return DB::transaction(function () use ($sesion, $usuarioCierre, $denominaciones, $contadoCentavos, $observaciones) {
            $sesion = SesionCaja::query()->lockForUpdate()->findOrFail($sesion->id);

            if (! $sesion->estaAbierta()) {
                throw new DomainException('La sesión ya está cerrada; no puede cerrarse dos veces.');
            }

            $esperadoCentavos = $this->calcularEfectivoEsperado($sesion);
            $diferenciaCentavos = $contadoCentavos - $esperadoCentavos;

            if ($diferenciaCentavos !== 0 && trim((string) $observaciones) === '') {
                throw new DomainException('Existe una diferencia de caja; registra la observación obligatoria.');
            }

            $arqueo = $this->registrarArqueo($sesion, $usuarioCierre, $denominaciones);

            if (Money::aCentavos($arqueo->efectivo_contado) !== $contadoCentavos) {
                throw new DomainException('El efectivo contado no coincide con el arqueo capturado.');
            }

            $sesion->update([
                'estado' => SesionCaja::ESTADO_CERRADA,
                'user_id_cierre' => $usuarioCierre->id,
                'closed_at' => now(),
                'efectivo_esperado' => Money::aPrecio($esperadoCentavos),
                'efectivo_contado' => Money::aPrecio($contadoCentavos),
                'diferencia' => Money::aPrecio($diferenciaCentavos),
                'observaciones_cierre' => trim((string) $observaciones) !== '' ? $observaciones : null,
            ]);

            return $sesion;
        });
    }

    /**
     * Datos consolidados del corte de caja (compartidos por web / PDF / XLSX).
     * Montos en pesos (string) listos para presentación; los totales derivan
     * del flujo real de pagos y movimientos, nunca de un precio supuesto.
     */
    public function datosCorte(SesionCaja $sesion): array
    {
        $sesion->loadMissing([
            'caja',
            'usuarioApertura',
            'usuarioCierre',
            'pagos',
            'movimientos',
            'arqueos.denominaciones',
        ]);

        $pagos = $sesion->pagos;

        $pagosEfectivo = $pagos->filter(fn ($p) => $p->metodo === PagoVenta::METODO_EFECTIVO && $p->origen !== PagoVenta::ORIGEN_LEGACY);
        $pagosTarjeta = $pagos->filter(fn ($p) => $p->metodo === PagoVenta::METODO_TARJETA);
        $pagosTransferencia = $pagos->filter(fn ($p) => $p->metodo === PagoVenta::METODO_TRANSFERENCIA);

        $efectivoAplicado = $pagosEfectivo->sum(fn ($p) => Money::aCentavos($p->monto_aplicado));
        $efectivoRecibidoBruto = $pagosEfectivo->sum(fn ($p) => Money::aCentavos($p->efectivo_recibido));
        $cambioEntregado = $pagosEfectivo->sum(fn ($p) => Money::aCentavos($p->cambio_entregado));

        $movs = $sesion->movimientos;

        $entradasManuales = $movs->filter(fn ($m) => $m->tipo === MovimientoCaja::TIPO_ENTRADA_MANUAL)->sum(fn ($m) => Money::aCentavos($m->monto));
        $retiros = $movs->filter(fn ($m) => $m->tipo === MovimientoCaja::TIPO_RETIRO)->sum(fn ($m) => Money::aCentavos($m->monto));
        $reembolsos = $movs->filter(fn ($m) => $m->tipo === MovimientoCaja::TIPO_REEMBOLSO_EFECTIVO)->sum(fn ($m) => Money::aCentavos($m->monto));
        $ajustesEntrada = $movs->filter(fn ($m) => $m->tipo === MovimientoCaja::TIPO_AJUSTE && $m->esEntrada())->sum(fn ($m) => Money::aCentavos($m->monto));
        $ajustesSalida = $movs->filter(fn ($m) => $m->tipo === MovimientoCaja::TIPO_AJUSTE && $m->esSalida())->sum(fn ($m) => Money::aCentavos($m->monto));

        $esperado = $this->calcularEfectivoEsperado($sesion);
        $contadoCentavos = $sesion->efectivo_contado !== null ? Money::aCentavos($sesion->efectivo_contado) : null;

        $porMetodo = [];

        foreach ([PagoVenta::METODO_EFECTIVO, PagoVenta::METODO_TARJETA, PagoVenta::METODO_TRANSFERENCIA] as $metodo) {
            $porMetodo[$metodo] = Money::aPrecio($pagos->filter(fn ($p) => $p->metodo === $metodo)->sum(fn ($p) => Money::aCentavos($p->monto_aplicado)));
        }

        $ventasTotales = Money::aPrecio($pagos->sum(fn ($p) => Money::aCentavos($p->monto_aplicado)));

        return [
            'caja_nombre' => $sesion->caja?->nombre,
            'caja_codigo' => $sesion->caja?->codigo,
            'folio' => $sesion->folio,
            'operador' => $sesion->usuarioApertura?->name,
            'cerrado_por' => $sesion->usuarioCierre?->name,
            'apertura' => $sesion->opened_at,
            'cierre' => $sesion->closed_at,
            'fondo_inicial' => $sesion->fondo_inicial,
            'ventas_totales' => $ventasTotales,
            'pagos_por_metodo' => $porMetodo,
            'efectivo_aplicado' => Money::aPrecio($efectivoAplicado),
            'efectivo_recibido_bruto' => Money::aPrecio($efectivoRecibidoBruto),
            'cambio_entregado' => Money::aPrecio($cambioEntregado),
            'efectivo_neto' => Money::aPrecio($efectivoAplicado - $cambioEntregado),
            'entradas_manuales' => Money::aPrecio($entradasManuales),
            'retiros' => Money::aPrecio($retiros),
            'reembolsos' => Money::aPrecio($reembolsos),
            'ajustes_entrada' => Money::aPrecio($ajustesEntrada),
            'ajustes_salida' => Money::aPrecio($ajustesSalida),
            'esperado' => Money::aPrecio($esperado),
            'contado' => $contadoCentavos !== null ? Money::aPrecio($contadoCentavos) : null,
            'diferencia' => $contadoCentavos !== null ? Money::aPrecio($contadoCentavos - $esperado) : null,
            'denominaciones' => $sesion->arqueos->flatMap(fn ($a) => $a->denominaciones),
            'movimientos' => $movs,
            'pagos' => $pagos,
            'observaciones_cierre' => $sesion->observaciones_cierre,
        ];
    }
}
