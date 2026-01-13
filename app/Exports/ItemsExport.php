<?php

namespace App\Exports;

use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class ItemsExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(private readonly Builder $query)
    {
    }

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
            'ID',
            'Código',
            'Serie',
            'Marca',
            'Modelo',
            'Categoría',
            'Ubicación',
            'Estado',
            'Notas',
            'Creado',
            'Actualizado',
        ];
    }

    /**
     * @param  Item  $item
     */
    public function map($item): array
    {
        return [
            (int) $item->id,
            (string) ($item->codigo ?? ''),
            (string) ($item->serie ?? ''),
            (string) ($item->marca ?? ''),
            (string) ($item->modelo ?? ''),
            (string) ($item->categoria?->nombre ?? ''),
            (string) ($item->ubicacion?->nombre ?? ''),
            (string) ($item->estado ?? ''),
            (string) ($item->notas ?? ''),
            $item->created_at?->format('Y-m-d H:i') ?? '',
            $item->updated_at?->format('Y-m-d H:i') ?? '',
        ];
    }
}
