<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\Ubicacion;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        // Opcional después:
        // $this->middleware('permission:dashboard.ver')->only(['index']);
    }

    public function index()
    {
        // KPIs
        $kpis = Item::query()
            ->selectRaw("
                count(*) as total,
                count(*) filter (where estado='DISPONIBLE') as disponible,
                count(*) filter (where estado='RESERVADO') as reservado,
                count(*) filter (where estado='REPARACION') as reparacion,
                count(*) filter (where estado='VENDIDO') as vendido,
                count(*) filter (where estado='BAJA') as baja
            ")
            ->first();

        $trashCount = Item::onlyTrashed()->count();

        // Top ubicaciones por cantidad de items
        $topUbicaciones = Ubicacion::query()
            ->withCount('items')
            ->orderByDesc('items_count')
            ->limit(8)
            ->get();

        // Top categorías por cantidad de items
        $topCategorias = Categoria::query()
            ->withCount('items')
            ->orderByDesc('items_count')
            ->limit(8)
            ->get();

        // Últimos movimientos (evita que se meta el orderBy global)
        $ultMovs = Movimiento::query()
            ->with(['item:id,codigo', 'user:id,name'])
            ->reorder('fecha', 'desc')   // <- mata orderBy global previo
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        // Movimientos últimos 7 días (groupBy + Postgres = sin order global)
        $movs7d = Movimiento::query()
            ->withoutGlobalScopes() // <- clave si Movimiento trae scope global con orderBy
            ->selectRaw("date(fecha) as dia, count(*) as total")
            ->where('fecha', '>=', now()->subDays(6)->startOfDay())
            ->groupBy(DB::raw('date(fecha)'))
            ->reorder('dia', 'asc') // <- asegura que solo ordene por el alias
            ->get();

        return view('dashboard', compact(
            'kpis',
            'trashCount',
            'topUbicaciones',
            'topCategorias',
            'ultMovs',
            'movs7d'
        ));
    }
}
