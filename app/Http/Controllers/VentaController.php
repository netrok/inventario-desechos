<?php

namespace App\Http\Controllers;

use App\Models\Venta;

class VentaController extends Controller
{
    public function index()
    {
        $ventas = Venta::query()
            ->with('user')
            ->withCount('detalles')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('ventas.index', ['ventas' => $ventas]);
    }

    public function show(Venta $venta)
    {
        $venta->load([
            'user',
            'detalles.item',
            'detalles.item.categoria',
        ]);

        return view('ventas.show', ['venta' => $venta]);
    }
}
