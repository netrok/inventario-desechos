<?php

namespace App\Services;

use App\Models\Caja;
use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\MovimientoCaja;
use App\Models\MovimientoCxC;
use App\Models\ReembolsoPostventa;
use App\Models\SesionCaja;
use App\Models\User;
use App\Models\Venta;
use App\Support\Money;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Núcleo de dominio CxC (B15.2).
 *
 * CRÉDITO = DEUDA, no dinero. PagoVenta sigue representando exclusivamente el
 * dinero realmente aplicado al checkout; movimientos_cxc es el ledger económico
 * de la deuda. Este servicio NUNCA crea PagoVenta.
 *
 * Orden global de locks (B15):
 *   SesionCaja -> Cliente -> Venta -> CuentaPorCobrar -> ...
 */
final class CuentaPorCobrarService
{
    /**
     * Único punto de entrada económico de B15.2: financia una venta con cliente.
     *
     * El objeto $venta recibido sirve SOLO para obtener $venta->id. Nunca se
     * confía en su cliente_id/total/estado/created_at (pueden estar obsoletos):
     * se relee la BD dentro de la transacción.
     *
     * @throws Throwable ModelNotFoundException|DomainException
     */
    public function crearParaVenta(Venta $venta, int $importeFinanciadoCentavos, User $user): CuentaPorCobrar
    {
        return DB::transaction(function () use ($venta, $importeFinanciadoCentavos, $user) {
            $ventaId = $venta->id;

            // --- PASO 0: validar el User actor persistido ----------------------
            // El responsable debe existir como fila real de users antes de
            // originar crédito. La FK movimientos_cxc.user_id (RESTRICT) es la
            // segunda barrera declarativa.
            if (! $user->exists || $user->getKey() === null) {
                throw new DomainException('El usuario actor no es válido.');
            }

            if (! User::query()->whereKey($user->getKey())->exists()) {
                throw new DomainException('El usuario actor no es válido.');
            }

            // --- PASO 1: lectura NO bloqueante de la Venta por ID --------------
            $ventaInicial = Venta::query()->find($ventaId);

            if ($ventaInicial === null) {
                throw (new ModelNotFoundException('Venta no encontrada.'))
                    ->setModel(Venta::class, [$ventaId]);
            }

            $clienteIdInicial = $ventaInicial->cliente_id;

            if ($clienteIdInicial === null) {
                throw new DomainException('La venta no tiene cliente y no puede financiarse.');
            }

            // --- PASO 2: lock del Cliente (mutex global de exposición) --------
            /** @var Cliente $cliente */
            $cliente = Cliente::query()->lockForUpdate()->findOrFail($clienteIdInicial);

            // --- PASO 3: releer y bloquear la Venta ---------------------------
            /** @var Venta $ventaFinal */
            $ventaFinal = Venta::query()->lockForUpdate()->findOrFail($ventaId);

            if ((int) $ventaFinal->cliente_id !== (int) $cliente->id) {
                // El cliente cambió entre la lectura inicial y el lock: abortar
                // para no proseguir con un mutex equivocado. NUNCA bloquear otro
                // Cliente dentro de la misma transacción.
                throw new DomainException('El cliente de la venta cambió durante la operación; reintente.');
            }

            // La CxC se origina como parte de la venta inicial: solo una venta
            // ACTIVA y sin historia postventa puede financiarse. Usa la Venta
            // releída bajo lock (fuente BD), nunca el objeto stale recibido.
            if ($ventaFinal->estado !== Venta::ESTADO_ACTIVA) {
                throw new DomainException('La cuenta por cobrar solo puede originarse desde una venta activa.');
            }

            if ($ventaFinal->documentosPostventa()->exists()) {
                throw new DomainException('No se puede originar crédito sobre una venta con operaciones postventa.');
            }

            // --- Validaciones server-side (BD como fuente) --------------------
            $this->validarFinanciamiento($cliente, $ventaFinal, $importeFinanciadoCentavos);

            // El lock de Cliente es el mutex de exposición.
            $exposicionCentavos = $this->exposicionCentavosDelCliente($cliente);

            $limiteCentavos = Money::aCentavos($cliente->limite_credito);

            if ($exposicionCentavos + $importeFinanciadoCentavos > $limiteCentavos) {
                throw new DomainException('El financiamiento excede el límite de crédito del cliente.');
            }

            // --- Snapshot del plazo y vencimiento -----------------------------
            $diasCreditoAplicados = (int) $cliente->dias_credito;

            // Fecha CALENDARIO de Venta.created_at persistida; no se inventa una
            // fecha de origen si el registro de venta carece de una.
            if ($ventaFinal->created_at === null) {
                throw new DomainException('La venta no tiene fecha de origen válida para calcular el vencimiento.');
            }

            $fechaVenta = $ventaFinal->created_at
                ->copy()
                ->setTimezone(config('app.timezone', 'UTC'))
                ->startOfDay();

            $fechaVencimiento = $fechaVenta->copy()->addDays($diasCreditoAplicados)->toDateString();

            // --- Creación atómica: CxC + CARGO_INICIAL ------------------------
            $cuenta = CuentaPorCobrar::create([
                'venta_id' => $ventaId,
                'cliente_id' => $cliente->id,
                'importe_original_centavos' => $importeFinanciadoCentavos,
                'saldo_centavos' => $importeFinanciadoCentavos,
                'dias_credito_aplicados' => $diasCreditoAplicados,
                'fecha_vencimiento' => $fechaVencimiento,
                'estado' => CuentaPorCobrar::ESTADO_PENDIENTE,
            ]);

            MovimientoCxC::create([
                'cuenta_por_cobrar_id' => $cuenta->id,
                'user_id' => $user->id,
                'tipo' => MovimientoCxC::TIPO_CARGO_INICIAL,
                'monto_centavos' => $importeFinanciadoCentavos,
                'saldo_antes_centavos' => 0,
                'saldo_despues_centavos' => $importeFinanciadoCentavos,
                'metodo' => null,
                'referencia' => null,
                'movimiento_origen_id' => null,
            ]);

            return $cuenta;
        });
    }

    /**
     * B15.4 — ABONO (cobranza) sobre una CuentaPorCobrar existente.
     *
     * NO reutiliza las validaciones B15.1 de originación: el Cliente actual
     * (inactivo, credito_habilitado=false, otro límite/plazo) NO invalida la
     * deuda histórica. El Cliente se bloquea SOLO como mutex de exposición para
     * serializarse con crearParaVenta() de B15.2.
     *
     * Orden global de locks:
     *   - EFECTIVO:     1) SesionCaja  2) Cliente  3) Venta  4) CuentaPorCobrar
     *   - TARJETA/TRANSFERENCIA:       1) Cliente   2) Venta  3) CuentaPorCobrar
     *   (ver bloquearSesionCaja: NUNCA se introduce un lock de Caja, la caja
     *   solo se REVALIDA bajo lock de SesionCaja para no invertir el orden).
     *
     * Idempotencia: `operacionUuid` (server-side).
     *   1) PRECHECK read-only ANTES de locks/recursos: si la operación ya se
     *      reconoció, se devuelve el ABONO existente sin requerir caja, sin
     *      tocar saldo ni crear filas nuevas.
     *   2) Tras adquirir los locks (SesionCaja/Cliente/Venta/Cuenta) se VUELVE
     *      a consultar el UUID: si apareció concurrentemente, se valida la
     *      identidad y se devuelve el existente.
     *   3) El UNIQUE PostgreSQL parcial es la última barrera en BD (además de
     *      la UI); un disparo concurrente se traduce a DomainException, nunca
     *      se captura Throwable genérico.
     *
     * BARRERA ABSOLUTA: este método NUNCA inserta PagoVenta.
     */
    public function registrarAbono(
        CuentaPorCobrar $cuenta,
        int $montoCentavos,
        string $metodo,
        User $user,
        ?string $referencia,
        ?string $observaciones,
        string $operacionUuid
    ): MovimientoCxC {
        if (! in_array($metodo, MovimientoCxC::METODOS, true)) {
            throw new DomainException('El método de abono no es válido.');
        }

        if ($montoCentavos <= 0) {
            throw new DomainException('El abono debe registrar un monto mayor a cero.');
        }

        if (! Str::isUuid($operacionUuid)) {
            throw new DomainException('El identificador de operación del abono es inválido.');
        }

        $referencia = $this->normalizarReferencia($metodo, $referencia);

        return DB::transaction(function () use ($cuenta, $montoCentavos, $metodo, $user, $referencia, $observaciones, $operacionUuid) {
            // A) PRECHECK read-only por operacion_uuid ANTES de locks/recursos.
            //    Un retry legítimo (p. ej. ABONO EFECTIVO ya confirmado con la
            //    caja cerrada) debe RECONOCER la operación existente sin volver
            //    a exigir caja, sin reducir saldo y sin crear filas nuevas.
            $existente = MovimientoCxC::query()
                ->where('operacion_uuid', $operacionUuid)
                ->first();

            if ($existente !== null) {
                $this->validarAbonoIdempotente($existente, $cuenta, $montoCentavos, $metodo, $referencia);

                return $existente;
            }

            $sesion = null;

            // Lock 1 (solo EFECTIVO): Sesión de caja.
            if ($metodo === MovimientoCxC::METODO_EFECTIVO) {
                $sesion = $this->bloquearSesionCaja($user);
            }

            // Lock 2: Cliente (mutex de exposición; sin validar configuración actual).
            $clienteBloqueado = Cliente::query()->lockForUpdate()->findOrFail($cuenta->cliente_id);

            // Lock 3: Venta (orden global; fuente histórica de la deuda).
            $ventaBloqueada = Venta::query()->lockForUpdate()->findOrFail($cuenta->venta_id);

            // Lock 4: CuentaPorCobrar (autoridad del saldo).
            /** @var CuentaPorCobrar $cuentaBloqueada */
            $cuentaBloqueada = CuentaPorCobrar::query()->lockForUpdate()->findOrFail($cuenta->id);

            // FIX 5 (REV1): revalidar el grafo bajo lock. La instancia exterior
            // puede ser STALE; la fila bloqueada es la fuente de verdad.
            if ((int) $cuentaBloqueada->cliente_id !== (int) $clienteBloqueado->id) {
                throw new DomainException('El grafo de la cuenta cambió durante la operación; reintente.');
            }

            if ((int) $cuentaBloqueada->venta_id !== (int) $ventaBloqueada->id) {
                throw new DomainException('El grafo de la cuenta cambió durante la operación; reintente.');
            }

            // C) Recheck tras adquirir los locks: si la operación apareció
            //    concurrentemente entre el precheck y el lock, se reconoce.
            $existente = MovimientoCxC::query()
                ->where('operacion_uuid', $operacionUuid)
                ->first();

            if ($existente !== null) {
                $this->validarAbonoIdempotente($existente, $cuentaBloqueada, $montoCentavos, $metodo, $referencia);

                return $existente;
            }

            if (! in_array($cuentaBloqueada->estado, [
                CuentaPorCobrar::ESTADO_PENDIENTE,
                CuentaPorCobrar::ESTADO_PARCIAL,
            ], true)) {
                throw new DomainException('La cuenta no admite abonos en su estado actual.');
            }

            $saldoAntes = (int) $cuentaBloqueada->saldo_centavos;

            if ($montoCentavos > $saldoAntes) {
                throw new DomainException('El abono supera el saldo de la cuenta.');
            }

            $saldoDespues = $saldoAntes - $montoCentavos;

            try {
                $abono = MovimientoCxC::create([
                    'cuenta_por_cobrar_id' => $cuentaBloqueada->id,
                    'user_id' => $user->id,
                    'tipo' => MovimientoCxC::TIPO_ABONO,
                    'monto_centavos' => $montoCentavos,
                    'saldo_antes_centavos' => $saldoAntes,
                    'saldo_despues_centavos' => $saldoDespues,
                    'metodo' => $metodo,
                    'referencia' => $referencia,
                    'movimiento_origen_id' => null,
                    'observaciones' => $observaciones !== null && trim($observaciones) !== ''
                        ? trim($observaciones)
                        : null,
                    'operacion_uuid' => $operacionUuid,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Doble disparo CONCURRENTE: el índice UNIQUE de BD ganó.
                // Se aborta la transacción; al reintentar se reconoce la
                // operación ya registrada.
                throw new DomainException('La operación de abono ya fue registrada; verifica antes de reintentar.');
            }

            $cuentaBloqueada->update([
                'saldo_centavos' => $saldoDespues,
                'estado' => CuentaPorCobrar::estadoNormalDesdeSaldo(
                    (int) $cuentaBloqueada->importe_original_centavos,
                    $saldoDespues
                ),
            ]);

            if ($metodo === MovimientoCxC::METODO_EFECTIVO) {
                MovimientoCaja::create([
                    'sesion_caja_id' => $sesion->id,
                    'user_id' => $user->id,
                    'tipo' => MovimientoCaja::TIPO_ABONO_CXC_EFECTIVO,
                    'direccion' => MovimientoCaja::DIR_ENTRADA,
                    'monto' => Money::aPrecio($montoCentavos),
                    'movimiento_cxc_id' => $abono->id,
                    'concepto' => "Abono CxC {$cuentaBloqueada->folio}",
                ]);
            }

            return $abono;
        });
    }

    /**
     * B15.4 — REVERSA de un ABONO (corrección, nunca DELETE del ABONO).
     *
     * Solo reversa TOTAL del ABONO original. La BD ya obliga:
     *   - origen existente y tipo ABONO
     *   - misma cuenta
     *   - mismo importe
     *   - máximo una reversa por ABONO
     *
     * Una cuenta SALDADA SI puede reabrirse por reversa (SALDADA -> PARCIAL /
     * PENDIENTE según saldo). NO se valida el límite de crédito actual: la
     * reversa corrige una cobranza; el límite controla NUEVO financiamiento.
     *
     * Si el ABONO original fue EFECTIVO, requiere sesión de caja abierta y crea
     * un MovimientoCaja REVERSA_CXC_EFECTIVO SALIDA por el mismo importe.
     */
    public function reversarAbono(
        CuentaPorCobrar $cuenta,
        MovimientoCxC $abono,
        User $user,
        string $motivo
    ): MovimientoCxC {
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new DomainException('La reversa de un abono requiere un motivo.');
        }

        return DB::transaction(function () use ($cuenta, $abono, $user, $motivo) {
            $abonoEfectivoPreflight = $abono->metodo === MovimientoCxC::METODO_EFECTIVO;

            // Lock 1 (solo si el ABONO original fue EFECTIVO): Sesión de caja.
            $sesion = $abonoEfectivoPreflight ? $this->bloquearSesionCaja($user) : null;

            // Lock 2: Cliente (mutex de exposición).
            $clienteBloqueado = Cliente::query()->lockForUpdate()->findOrFail($cuenta->cliente_id);

            // Lock 3: Venta (orden global).
            $ventaBloqueada = Venta::query()->lockForUpdate()->findOrFail($cuenta->venta_id);

            // Lock 4: CuentaPorCobrar.
            /** @var CuentaPorCobrar $cuentaBloqueada */
            $cuentaBloqueada = CuentaPorCobrar::query()->lockForUpdate()->findOrFail($cuenta->id);

            // FIX 5 (REV1): revalidar el grafo bajo lock (la instancia exterior
            // puede ser STALE; la fila bloqueada es la fuente de verdad).
            if ((int) $cuentaBloqueada->cliente_id !== (int) $clienteBloqueado->id) {
                throw new DomainException('El grafo de la cuenta cambió durante la operación; reintente.');
            }

            if ((int) $cuentaBloqueada->venta_id !== (int) $ventaBloqueada->id) {
                throw new DomainException('El grafo de la cuenta cambió durante la operación; reintente.');
            }

            // Lock 5: MovimientoCxC origen (el ABONO), revalidado bajo lock.
            /** @var MovimientoCxC $abonoBloqueado */
            $abonoBloqueado = MovimientoCxC::query()
                ->lockForUpdate()
                ->findOrFail($abono->id);

            if ($abonoBloqueado->tipo !== MovimientoCxC::TIPO_ABONO) {
                throw new DomainException('Solo puede reversarse un movimiento tipo ABONO.');
            }

            if ((int) $abonoBloqueado->cuenta_por_cobrar_id !== (int) $cuentaBloqueada->id) {
                throw new DomainException('El abono no pertenece a esta cuenta por cobrar.');
            }

            if (MovimientoCxC::query()
                ->where('tipo', MovimientoCxC::TIPO_REVERSA_ABONO)
                ->where('movimiento_origen_id', $abonoBloqueado->id)
                ->exists()) {
                throw new DomainException('Este abono ya fue reversado.');
            }

            // B15.5 — INTERLOCK: un ABONO que ya financió un reembolso postventa
            // (reembolsos_postventa.origen = CXC_ABONO) no puede reversarse.
            // El trigger mxc_reversa_bloquea_reembolso refuerza esto a nivel BD;
            // aquí se lockea el ABONO, que es el mutex que ya serializa el
            // reembolso (reembolso_fuente_exacta hace FOR UPDATE del mismo).
            if (ReembolsoPostventa::query()
                ->where('movimiento_cxc_id', $abonoBloqueado->id)
                ->lockForUpdate()
                ->exists()) {
                throw new DomainException(
                    'El abono ya fue utilizado en una operación postventa y no puede reversarse.'
                );
            }

            if ($cuentaBloqueada->estado === CuentaPorCobrar::ESTADO_CANCELADA) {
                throw new DomainException('No puede reversarse un abono de una cuenta cancelada.');
            }

            $abonoEfectivo = $abonoBloqueado->metodo === MovimientoCxC::METODO_EFECTIVO;

            if ($abonoEfectivo !== $abonoEfectivoPreflight) {
                throw new DomainException('El método del abono cambió durante la operación; reintente.');
            }

            $importeOriginal = (int) $cuentaBloqueada->importe_original_centavos;
            $montoAbono = (int) $abonoBloqueado->monto_centavos;
            $saldoAntes = (int) $cuentaBloqueada->saldo_centavos;
            $saldoDespues = $saldoAntes + $montoAbono;

            if ($saldoDespues > $importeOriginal) {
                throw new DomainException('La reversa haría que el saldo exceda el importe original de la cuenta.');
            }

            $reversa = MovimientoCxC::create([
                'cuenta_por_cobrar_id' => $cuentaBloqueada->id,
                'user_id' => $user->id,
                'tipo' => MovimientoCxC::TIPO_REVERSA_ABONO,
                'monto_centavos' => $montoAbono,
                'saldo_antes_centavos' => $saldoAntes,
                'saldo_despues_centavos' => $saldoDespues,
                'metodo' => null,
                'referencia' => null,
                'movimiento_origen_id' => $abonoBloqueado->id,
                'observaciones' => $motivo,
            ]);

            $cuentaBloqueada->update([
                'saldo_centavos' => $saldoDespues,
                'estado' => CuentaPorCobrar::estadoNormalDesdeSaldo(
                    $importeOriginal,
                    $saldoDespues
                ),
            ]);

            if ($abonoEfectivo) {
                if (app(CajaService::class)->calcularEfectivoEsperado($sesion) < $montoAbono) {
                    throw new DomainException('El efectivo disponible en caja es insuficiente para la reversa.');
                }

                MovimientoCaja::create([
                    'sesion_caja_id' => $sesion->id,
                    'user_id' => $user->id,
                    'tipo' => MovimientoCaja::TIPO_REVERSA_CXC_EFECTIVO,
                    'direccion' => MovimientoCaja::DIR_SALIDA,
                    'monto' => Money::aPrecio($montoAbono),
                    'movimiento_cxc_id' => $reversa->id,
                    'concepto' => "Reversa de abono CxC {$cuentaBloqueada->folio}",
                ]);
            }

            return $reversa;
        });
    }

    /**
     * Sesión de caja ABIERTA del operador, bloqueada como PRIMER lock
     * (contexto físico para ABONOS/REVERSAS en efectivo). Nunca se acepta
     * sesion_caja_id del navegador.
     *
     * FIX 6 (REV1) — defense-in-depth (B14.3.1): además de resolver una sesión
     * ABIERTA por user_id_apertura, se REVALIDA server-side la relación ya
     * establecida con su caja:
     *   - la caja existe
     *   - la caja está ACTIVA (regla B14.3.1)
     *   - caja.usuario_asignado_id === user->id
     *
     * NO se introduce un lockForUpdate sobre Caja (ello invertiría el orden
     * global de locks B14/B15); la caja se valida bajo el lock de SesionCaja.
     * La BD ya impide construir estados incoherentes (B14.3.1 CHECKs + triggers
     * de apertura), por lo que esta revalidación es la segunda barrera.
     */
    private function bloquearSesionCaja(User $user): SesionCaja
    {
        $sesion = SesionCaja::query()
            ->lockForUpdate()
            ->with('caja')
            ->where('user_id_apertura', $user->id)
            ->abiertas()
            ->first();

        if (! $sesion instanceof SesionCaja) {
            throw new DomainException('Debes abrir una caja para registrar abonos en efectivo.');
        }

        $caja = $sesion->caja;

        if (! $caja instanceof Caja) {
            throw new DomainException('La caja de tu sesión no existe; contacta al administrador.');
        }

        if (! $caja->activa) {
            throw new DomainException('La caja de tu sesión está inactiva; contacta al administrador.');
        }

        if ((int) $caja->usuario_asignado_id !== (int) $user->id) {
            throw new DomainException('La caja de tu sesión no coincide con tu operador asignado.');
        }

        return $sesion;
    }

    /**
     * Referencia: obligatoria para TARJETA/TRANSFERENCIA, opcional para
     * EFECTIVO. Límite razonable de 100 caracteres.
     */
    private function normalizarReferencia(string $metodo, ?string $referencia): ?string
    {
        $referencia = $referencia !== null ? trim($referencia) : null;

        if ($metodo !== MovimientoCxC::METODO_EFECTIVO && ($referencia === null || $referencia === '')) {
            throw new DomainException("El abono {$metodo} requiere una referencia.");
        }

        if ($referencia !== null && mb_strlen($referencia) > 100) {
            throw new DomainException('La referencia no puede exceder 100 caracteres.');
        }

        return $referencia === '' ? null : $referencia;
    }

    /**
     * Un UUID repetido solo es idempotente si describe EXACTAMENTE el mismo
     * ABONO: mismo tipo (ABONO), misma cuenta por cobrar, mismo monto, mismo
     * método y misma referencia NORMALIZADA (trim; '' -> null). El FIX 3 (REV1)
     * incorpora la referencia a la identidad económica. Las observaciones NO
     * forman parte de la identidad: un retry puede omitirlas o variarlas sin
     * que la operación deje de reconocerse (se documenta explícitamente).
     */
    private function validarAbonoIdempotente(
        MovimientoCxC $existente,
        CuentaPorCobrar $cuenta,
        int $montoCentavos,
        string $metodo,
        ?string $referencia
    ): void {
        if ($existente->tipo !== MovimientoCxC::TIPO_ABONO) {
            throw new DomainException('El UUID de operación ya fue usado para una operación distinta.');
        }

        if ((int) $existente->cuenta_por_cobrar_id !== (int) $cuenta->id) {
            throw new DomainException('El UUID de operación ya fue usado para otra cuenta por cobrar.');
        }

        if ((int) $existente->monto_centavos !== $montoCentavos || $existente->metodo !== $metodo) {
            throw new DomainException('El UUID de operación ya fue usado con datos incompatibles.');
        }

        $referenciaExistente = $existente->referencia !== null ? trim($existente->referencia) : null;
        $referenciaNormalizada = $referencia !== null ? trim($referencia) : null;
        $referenciaExistente = $referenciaExistente === '' ? null : $referenciaExistente;
        $referenciaNormalizada = $referenciaNormalizada === '' ? null : $referenciaNormalizada;

        if ($referenciaExistente !== $referenciaNormalizada) {
            throw new DomainException('El UUID de operación ya fue usado con una referencia distinta.');
        }
    }

    private function validarFinanciamiento(Cliente $cliente, Venta $venta, int $importeFinanciadoCentavos): void
    {
        if (! $cliente->credito_habilitado) {
            throw new DomainException('El cliente no tiene crédito habilitado.');
        }

        $limiteCentavos = Money::aCentavos($cliente->limite_credito);
        if ($limiteCentavos <= 0) {
            throw new DomainException('El cliente no tiene un límite de crédito válido.');
        }

        if ($cliente->dias_credito === null || (int) $cliente->dias_credito <= 0) {
            throw new DomainException('El cliente no tiene un plazo de crédito válido.');
        }

        if ($importeFinanciadoCentavos <= 0) {
            throw new DomainException('El importe a financiar debe ser mayor que cero.');
        }

        $totalCentavos = Money::aCentavos($venta->total);
        if ($importeFinanciadoCentavos > $totalCentavos) {
            throw new DomainException('El importe a financiar no puede exceder el total de la venta.');
        }

        if ($venta->cliente_id === null || (int) $venta->cliente_id !== (int) $cliente->id) {
            throw new DomainException('La venta no corresponde a este cliente.');
        }

        if (CuentaPorCobrar::where('venta_id', $venta->id)->exists()) {
            throw new DomainException('Esta venta ya tiene una cuenta por cobrar.');
        }
    }

    /**
     * Exposición actual del cliente: suma de saldos vivos (saldo > 0), en
     * centavos. Se calcula CON el Cliente ya bloqueado (mutex de exposición).
     */
    private function exposicionCentavosDelCliente(Cliente $cliente): int
    {
        return (int) CuentaPorCobrar::query()
            ->where('cliente_id', $cliente->id)
            ->where('saldo_centavos', '>', 0)
            ->sum('saldo_centavos');
    }
}
