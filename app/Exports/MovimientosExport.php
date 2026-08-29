<?php

namespace App\Exports;

use App\Models\Movimiento;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class MovimientosExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(private readonly Builder $query) {}

    public function query(): Builder
    {
        return (clone $this->query)->with([
            'item:id,codigo',
            'user:id,name',
            'deUbicacion:id,nombre',
            'aUbicacion:id,nombre',
        ]);
    }

    public function headings(): array
    {
        return [
            'Fecha',
            'Código',
            'Tipo',
            'Usuario',
            'Estado anterior',
            'Estado nuevo',
            'Ubicación anterior',
            'Ubicación nueva',
            'Notas',
        ];
    }

    /**
     * @param  Movimiento  $movimiento
     */
    public function map($movimiento): array
    {
        return [
            $movimiento->created_at?->format('Y-m-d H:i') ?? '',
            (string) ($movimiento->item?->codigo ?? ''),
            (string) ($movimiento->tipo ?? ''),
            (string) ($movimiento->user?->name ?? ''),
            (string) ($movimiento->de_estado ?? ''),
            (string) ($movimiento->a_estado ?? ''),
            (string) ($movimiento->deUbicacion?->nombre ?? ''),
            (string) ($movimiento->aUbicacion?->nombre ?? ''),
            (string) ($movimiento->notas ?? ''),
        ];
    }
}
