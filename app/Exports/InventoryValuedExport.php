<?php

namespace App\Exports;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Inventario Valuado (XLSX).
 *
 * Dos hojas llamadas "Resumen" (KPIs + leyenda + agrupaciones) y "Detalle"
 * (items). Los montos se mantienen exactos: provienen de agregados PostgreSQL
 * numeric y se presentan con Money (centavos enteros), sin float.
 */
class InventoryValuedExport implements WithMultipleSheets
{
    public function __construct(
        private readonly array $filters,
        private readonly array $kpis,
        private readonly array $agrupaciones,
        private readonly Builder $query,
    ) {}

    public function sheets(): array
    {
        return [
            new InventoryValuedResumenSheet($this->filters, $this->kpis, $this->agrupaciones),
            new InventoryValuedDetalleSheet($this->query),
        ];
    }

    public static function money(?string $valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        return Money::formatear(Money::aPrecio(Money::aCentavos($valor)));
    }
}

class InventoryValuedResumenSheet implements FromArray, ShouldAutoSize, WithTitle
{
    public function __construct(
        private readonly array $filters,
        private readonly array $kpis,
        private readonly array $agrupaciones,
    ) {}

    public function title(): string
    {
        return 'Resumen';
    }

    public function array(): array
    {
        $k = $this->kpis;
        $rows = [];

        $rows[] = ['INVENTARIO VALUADO A PRECIO DE VENTA'];
        $rows[] = ['Valuación comercial estimada a precio de venta.'];
        $rows[] = ['Este reporte utiliza el precio de venta actual registrado en cada equipo. No representa costo histórico, valor en libros ni valuación contable.'];
        $rows[] = [];
        $rows[] = ['Generado', now()->format('Y-m-d H:i')];
        $rows[] = [];

        $rows[] = ['RESUMEN'];
        $rows[] = ['Equipos actuales', $k['equipos']];
        $rows[] = ['Equipos con precio', $k['con_precio']];
        $rows[] = ['Equipos sin precio', $k['sin_precio']];
        $rows[] = ['Equipos con precio cero', $k['precio_cero']];
        $rows[] = ['Cobertura de valuación', sprintf('%.1f%%', $k['cobertura'] * 100)];
        $rows[] = ['Valor comercial registrado', self::money($k['valor_comercial'])];
        $rows[] = ['Valor disponible/reservado', self::money($k['valor_disponible_reservado'])];
        $rows[] = ['Valor en revisión', self::money($k['valor_revision'])];
        $rows[] = ['Valor en baja', self::money($k['valor_baja'])];
        $rows[] = [];

        $f = $this->filters;
        $rows[] = ['FILTROS APLICADOS'];
        $rows[] = ['Código', $f['codigo'] ?: 'Todos'];
        $rows[] = ['Estado', $f['estado'] ?: 'Todos'];
        $rows[] = ['Ubicación', $f['ubicacion_name'] ?: 'Todas'];
        $rows[] = ['Categoría', $f['categoria_name'] ?: 'Todas'];
        $rows[] = ['Marca', $f['marca'] ?: 'Todas'];
        $rows[] = ['Modelo', $f['modelo'] ?: 'Todos'];
        $rows[] = ['Serie', $f['serie'] ?: 'Todas'];
        $rows[] = ['Estado de precio', $f['estado_precio'] ?: 'Todos'];
        $rows[] = ['Precio mínimo', $f['precio_min'] ?? '—'];
        $rows[] = ['Precio máximo', $f['precio_max'] ?? '—'];
        $rows[] = [];

        $rows[] = ['POR ESTADO'];
        $rows[] = ['Estado', 'Equipos', 'Con precio', 'Valor'];
        foreach ($this->agrupaciones['estado'] as $row) {
            $rows[] = [$row['grupo'], $row['equipos'], $row['con_precio'], self::money($row['valor'])];
        }
        $rows[] = [];

        $rows[] = ['POR CATEGORÍA'];
        $rows[] = ['Categoría', 'Equipos', 'Con precio', 'Valor'];
        foreach ($this->agrupaciones['categoria'] as $row) {
            $rows[] = [$row['grupo'], $row['equipos'], $row['con_precio'], self::money($row['valor'])];
        }
        $rows[] = [];

        $rows[] = ['POR UBICACIÓN'];
        $rows[] = ['Ubicación', 'Equipos', 'Con precio', 'Valor'];
        foreach ($this->agrupaciones['ubicacion'] as $row) {
            $rows[] = [$row['grupo'], $row['equipos'], $row['con_precio'], self::money($row['valor'])];
        }

        return $rows;
    }

    private static function money(?string $valor): string
    {
        return InventoryValuedExport::money($valor);
    }
}

class InventoryValuedDetalleSheet implements \Maatwebsite\Excel\Concerns\FromQuery, \Maatwebsite\Excel\Concerns\WithHeadings, \Maatwebsite\Excel\Concerns\WithMapping, ShouldAutoSize, WithTitle
{
    public function __construct(private readonly Builder $query) {}

    public function title(): string
    {
        return 'Detalle';
    }

    public function query(): Builder
    {
        $q = clone $this->query;

        return $q->orderByDesc('id')->with(['categoria:id,nombre', 'ubicacion:id,nombre']);
    }

    public function headings(): array
    {
        return [
            'Código',
            'Categoría',
            'Marca',
            'Modelo',
            'Serie',
            'Estado',
            'Ubicación',
            'Precio de venta',
        ];
    }

    public function map($item): array
    {
        return [
            (string) ($item->codigo ?? ''),
            (string) ($item->categoria?->nombre ?? ''),
            (string) ($item->marca ?? ''),
            (string) ($item->modelo ?? ''),
            (string) ($item->serie ?? ''),
            (string) ($item->estado ?? ''),
            (string) ($item->ubicacion?->nombre ?? ''),
            $item->precio === null ? 'Sin precio' : InventoryValuedExport::money((string) $item->precio),
        ];
    }
}
