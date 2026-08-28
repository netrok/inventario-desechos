<?php

namespace App\Exports;

use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ReportInventoryExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(private readonly Builder $query) {}

    public function query(): Builder
    {
        // Clonar para NO modificar el query original del controller
        $q = clone $this->query;

        // Cargar relaciones para evitar N+1 en map()
        return $q->with([
            'categoria:id,nombre',
            'ubicacion:id,nombre',
        ]);
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
            'Fecha de alta',
            'Notas',
        ];
    }

    /**
     * @param  Item  $item
     */
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
            $item->created_at?->format('Y-m-d') ?? '',
            (string) ($item->notas ?? ''),
        ];
    }
}
