<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Exporta el corte de caja consolidado (B14) a XLSX.
 * Datos provienen de CajaService::datosCorte() — mismos totales que web/PDF.
 */
class CorteCajaExport implements FromArray, ShouldAutoSize, WithHeadings
{
    public function __construct(private readonly array $d) {}

    public function headings(): array
    {
        return ['Concepto', 'Valor'];
    }

    public function array(): array
    {
        $d = $this->d;

        $rows = [
            ['Caja', ($d['caja_codigo'] ?? '').' · '.($d['caja_nombre'] ?? '')],
            ['Folio de sesión', $d['folio'] ?? ''],
            ['Operador', $d['operador'] ?? ''],
            ['Cerrado por', $d['cerrado_por'] ?? ''],
            ['Apertura', isset($d['apertura']) ? $d['apertura']->format('Y-m-d H:i:s') : ''],
            ['Cierre', isset($d['cierre']) ? $d['cierre']->format('Y-m-d H:i:s') : ''],
            ['Fondo inicial', $d['fondo_inicial'] ?? '0.00'],
            [],
            ['VENTAS TOTALES', $d['ventas_totales'] ?? '0.00'],
            ['Pagos Efectivo', $d['pagos_por_metodo']['EFECTIVO'] ?? '0.00'],
            ['Pagos Tarjeta', $d['pagos_por_metodo']['TARJETA'] ?? '0.00'],
            ['Pagos Transferencia', $d['pagos_por_metodo']['TRANSFERENCIA'] ?? '0.00'],
            [],
            ['EFECTIVO DETALLADO', ''],
            ['Efectivo recibido (bruto)', $d['efectivo_recibido_bruto'] ?? '0.00'],
            ['Cambio entregado', $d['cambio_entregado'] ?? '0.00'],
            ['Efectivo neto aplicado', $d['efectivo_neto'] ?? '0.00'],
            [],
            ['OPERACIONES', ''],
            ['Entradas manuales', $d['entradas_manuales'] ?? '0.00'],
            ['Retiros', $d['retiros'] ?? '0.00'],
            ['Reembolsos en efectivo', $d['reembolsos'] ?? '0.00'],
            ['Ajustes (entrada)', $d['ajustes_entrada'] ?? '0.00'],
            ['Ajustes (salida)', $d['ajustes_salida'] ?? '0.00'],
            [],
            ['ARQUEO Y CIERRE', ''],
            ['Efectivo esperado', $d['esperado'] ?? '0.00'],
            ['Efectivo contado', $d['contado'] ?? '—'],
            ['Diferencia', $d['diferencia'] ?? '—'],
            ['Observaciones de cierre', $d['observaciones_cierre'] ?? ''],
        ];

        // Desglose por denominaciones del arqueo.
        if (! empty($d['denominaciones'])) {
            $rows[] = [];
            $rows[] = ['ARQUEO POR DENOMINACIONES', ''];

            foreach ($d['denominaciones'] as $den) {
                $rows[] = [
                    '$'.$den->denominacion.' × '.$den->cantidad,
                    $den->subtotal,
                ];
            }
        }

        return $rows;
    }
}
