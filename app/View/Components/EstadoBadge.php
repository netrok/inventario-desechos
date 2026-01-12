<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class EstadoBadge extends Component
{
    public string $estado;
    public string $class;

    public function __construct(string $estado)
    {
        $this->estado = strtoupper($estado);

        $this->class = match ($this->estado) {
            'DISPONIBLE' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'RESERVADO'  => 'bg-amber-50 text-amber-700 border-amber-200',
            'VENDIDO'    => 'bg-slate-100 text-slate-700 border-slate-200',
            'REPARACION' => 'bg-blue-50 text-blue-700 border-blue-200',
            'BAJA'       => 'bg-rose-50 text-rose-700 border-rose-200',
            default      => 'bg-gray-50 text-gray-700 border-gray-200',
        };
    }

    public function render(): View|Closure|string
    {
        return view('components.estado-badge');
    }
}
