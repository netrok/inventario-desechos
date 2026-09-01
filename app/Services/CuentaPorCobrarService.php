<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\CuentaPorCobrar;
use App\Models\MovimientoCxC;
use App\Models\User;
use App\Models\Venta;
use App\Support\Money;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
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
