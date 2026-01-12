<?php

namespace App\Http\Controllers;

use App\Models\Item;
use App\Models\Ubicacion;

class DashboardController extends Controller
{
    public function index()
    {
        $total = Item::count();

        $porEstado = Item::query()
            ->selectRaw('estado, COUNT(*)::int as total')
            ->groupBy('estado')
            ->orderBy('estado')
            ->pluck('total', 'estado')
            ->toArray();

        // Asegura que todos los estados existan en el array
        foreach (Item::ESTADOS as $e) {
            $porEstado[$e] = $porEstado[$e] ?? 0;
        }

        $porUbicacion = Ubicacion::query()
            ->withCount('items')
            ->orderByDesc('items_count')
            ->get();

        $enPapelera = Item::onlyTrashed()->count();

        return view('dashboard', compact('total','porEstado','porUbicacion','enPapelera'));
    }
}
