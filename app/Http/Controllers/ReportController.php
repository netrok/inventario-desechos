<?php

namespace App\Http\Controllers;

use App\Exports\InventoryValuedExport;
use App\Exports\MovimientosExport;
use App\Exports\ReportInventoryExport;
use App\Models\Categoria;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\Ubicacion;
use App\Models\User;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    /**
     * Tipos de movimiento históricos y vigentes.
     */
    private const TIPOS = [
        Movimiento::TIPO_ALTA,
        Movimiento::TIPO_CAMBIO_ESTADO,
        Movimiento::TIPO_TRASLADO,
        Movimiento::TIPO_AJUSTE,
        Movimiento::TIPO_BAJA,
        Movimiento::TIPO_VENTA,
        Movimiento::TIPO_RESTAURAR,
        Movimiento::TIPO_CANCELACION_VENTA,
        Movimiento::TIPO_DEVOLUCION_VENTA,
        Movimiento::TIPO_REVISION_DEVOLUCION,
    ];

    public function index()
    {
        return view('reports.index');
    }

    /*
     * ============== INVENTARIO ==============
     */

    private function inventoryFilters(Request $request): array
    {
        $validated = $request->validate([
            'alta_desde' => ['nullable', 'date'],
            'alta_hasta' => ['nullable', 'date'],
            'codigo' => ['nullable', 'string', 'max:40'],
            'marca' => ['nullable', 'string', 'max:80'],
            'modelo' => ['nullable', 'string', 'max:120'],
            'serie' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', Rule::in(Item::ESTADOS)],
            'ubicacion_id' => ['nullable', 'integer', 'exists:ubicaciones,id'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
        ]);

        $ubicacionId = ! empty($validated['ubicacion_id']) ? $validated['ubicacion_id'] : null;
        $categoriaId = ! empty($validated['categoria_id']) ? $validated['categoria_id'] : null;

        $altaDesde = ! empty($validated['alta_desde']) ? Carbon::parse($validated['alta_desde']) : null;
        $altaHasta = ! empty($validated['alta_hasta']) ? Carbon::parse($validated['alta_hasta']) : null;

        if ($altaDesde && $altaHasta && $altaHasta->lt($altaDesde)) {
            throw ValidationException::withMessages([
                'alta_hasta' => 'La fecha "hasta" debe ser posterior o igual a "desde".',
            ]);
        }

        return [
            'codigo' => trim((string) ($validated['codigo'] ?? '')),
            'alta_desde' => $altaDesde,
            'alta_hasta' => $altaHasta,
            'marca' => trim((string) ($validated['marca'] ?? '')),
            'modelo' => trim((string) ($validated['modelo'] ?? '')),
            'serie' => trim((string) ($validated['serie'] ?? '')),
            'estado' => $validated['estado'] ?? null,
            'ubicacion_id' => $ubicacionId,
            'categoria_id' => $categoriaId,

            // Nombres legibles para PDF/chips (evita exponer IDs)
            'ubicacion_name' => $ubicacionId
                ? Ubicacion::query()->whereKey($ubicacionId)->value('nombre')
                : null,
            'categoria_name' => $categoriaId
                ? Categoria::query()->whereKey($categoriaId)->value('nombre')
                : null,
        ];
    }

    /**
     * Query base del inventario con filtros (web + XLSX + PDF comparten esta lógica).
     */
    private function inventoryQuery(array $filters): Builder
    {
        $codigo = $filters['codigo'] ?? '';
        $marca = $filters['marca'] ?? '';
        $modelo = $filters['modelo'] ?? '';
        $serie = $filters['serie'] ?? '';
        $estado = $filters['estado'] ?? null;
        $ubicacionId = $filters['ubicacion_id'] ?? null;
        $categoriaId = $filters['categoria_id'] ?? null;
        $altaDesde = $filters['alta_desde'] ?? null;
        $altaHasta = $filters['alta_hasta'] ?? null;

        return Item::query()
            ->with(['ubicacion:id,nombre', 'categoria:id,nombre'])
            ->when($codigo !== '', fn (Builder $qq) => $qq->where('codigo', 'ilike', "%{$codigo}%"))
            ->when($marca !== '', fn (Builder $qq) => $qq->where('marca', 'ilike', "%{$marca}%"))
            ->when($modelo !== '', fn (Builder $qq) => $qq->where('modelo', 'ilike', "%{$modelo}%"))
            ->when($serie !== '', fn (Builder $qq) => $qq->where('serie', 'ilike', "%{$serie}%"))
            ->when($estado, fn (Builder $qq) => $qq->where('estado', $estado))
            ->when($ubicacionId, fn (Builder $qq) => $qq->where('ubicacion_id', $ubicacionId))
            ->when($categoriaId, fn (Builder $qq) => $qq->where('categoria_id', $categoriaId))
            ->when($altaDesde, fn (Builder $qq) => $qq->where('created_at', '>=', $altaDesde->copy()->startOfDay()))
            ->when($altaHasta, fn (Builder $qq) => $qq->where('created_at', '<=', $altaHasta->copy()->endOfDay()));
    }

    public function inventory(Request $request)
    {
        $filters = $this->inventoryFilters($request);
        $base = $this->inventoryQuery($filters);

        $items = (clone $base)->orderByDesc('id')->paginate(25)->withQueryString();
        $total = (clone $base)->count();

        return view('reports.inventory', [
            'items' => $items,
            'total' => $total,
            'ubicaciones' => Ubicacion::orderBy('nombre')->get(),
            'categorias' => Categoria::orderBy('nombre')->get(),
            'estados' => Item::ESTADOS,
            'filters' => $filters,
        ]);
    }

    public function inventoryXlsx(Request $request)
    {
        $filters = $this->inventoryFilters($request);
        $query = $this->inventoryQuery($filters)->orderByDesc('id');

        return Excel::download(new ReportInventoryExport($query), $this->filename('reports_inventory', 'xlsx'));
    }

    public function inventoryPdf(Request $request)
    {
        $filters = $this->inventoryFilters($request);
        $items = $this->inventoryQuery($filters)->orderByDesc('id')->get();

        $pdf = Pdf::loadView('reports.pdf.inventory', [
            'items' => $items,
            'filters' => $filters,
            'generatedAt' => now(),
        ])
            ->setPaper('a4', 'landscape')
            ->setOptions([
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => true,
                'dpi' => 96,
                'defaultFont' => 'DejaVu Sans',
            ]);

        return $pdf->download($this->filename('reports_inventory', 'pdf'));
    }

    /*
     * ============== INVENTARIO VALUADO ==============
     *
     * Valuación comercial estimada a precio de venta. Excluye SIEMPRE el
     * estado VENDIDO (no forma parte del inventario actual). No representa
     * costo histórico, valor en libros ni valuación contable.
     */

    private const ESTADOS_VALUADO = [
        'DISPONIBLE',
        'RESERVADO',
        'REPARACION',
        'DEVUELTO',
        'BAJA',
    ];

    private function inventoryValuedFilters(Request $request): array
    {
        $validated = $request->validate([
            'alta_desde' => ['nullable', 'date'],
            'alta_hasta' => ['nullable', 'date'],
            'codigo' => ['nullable', 'string', 'max:40'],
            'marca' => ['nullable', 'string', 'max:80'],
            'modelo' => ['nullable', 'string', 'max:120'],
            'serie' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', Rule::in(self::ESTADOS_VALUADO)],
            'ubicacion_id' => ['nullable', 'integer', 'exists:ubicaciones,id'],
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
            'estado_precio' => ['nullable', Rule::in(['', 'con_precio', 'sin_precio', 'precio_cero'])],
            'precio_min' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'precio_max' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/'],
        ]);

        $ubicacionId = ! empty($validated['ubicacion_id']) ? $validated['ubicacion_id'] : null;
        $categoriaId = ! empty($validated['categoria_id']) ? $validated['categoria_id'] : null;

        $altaDesde = ! empty($validated['alta_desde']) ? Carbon::parse($validated['alta_desde']) : null;
        $altaHasta = ! empty($validated['alta_hasta']) ? Carbon::parse($validated['alta_hasta']) : null;

        if ($altaDesde && $altaHasta && $altaHasta->lt($altaDesde)) {
            throw ValidationException::withMessages([
                'alta_hasta' => 'La fecha "hasta" debe ser posterior o igual a "desde".',
            ]);
        }

        // Presencia explícita: "0" es un importe válido y NO debe tratarse como
        // campo ausente. Nunca usar empty() para importes monetarios.
        $campo = fn (string $key): bool => array_key_exists($key, $validated)
            && $validated[$key] !== null
            && $validated[$key] !== '';

        $precioMinCent = $campo('precio_min') ? Money::aCentavos($validated['precio_min']) : null;
        $precioMaxCent = $campo('precio_max') ? Money::aCentavos($validated['precio_max']) : null;

        if ($precioMinCent !== null && $precioMaxCent !== null && $precioMaxCent < $precioMinCent) {
            throw ValidationException::withMessages([
                'precio_max' => 'El precio máximo debe ser mayor o igual al mínimo.',
            ]);
        }

        return [
            'codigo' => trim((string) ($validated['codigo'] ?? '')),
            'alta_desde' => $altaDesde,
            'alta_hasta' => $altaHasta,
            'marca' => trim((string) ($validated['marca'] ?? '')),
            'modelo' => trim((string) ($validated['modelo'] ?? '')),
            'serie' => trim((string) ($validated['serie'] ?? '')),
            'estado' => $validated['estado'] ?? null,
            'ubicacion_id' => $ubicacionId,
            'categoria_id' => $categoriaId,
            'estado_precio' => $validated['estado_precio'] ?? '',
            'precio_min' => $precioMinCent !== null ? Money::aPrecio($precioMinCent) : null,
            'precio_max' => $precioMaxCent !== null ? Money::aPrecio($precioMaxCent) : null,

            'ubicacion_name' => $ubicacionId
                ? Ubicacion::query()->whereKey($ubicacionId)->value('nombre')
                : null,
            'categoria_name' => $categoriaId
                ? Categoria::query()->whereKey($categoriaId)->value('nombre')
                : null,
        ];
    }

    /**
     * Query base del inventario valuado con filtros.
     *
     * Todas las columnas van calificadas con "items." para que el query sea
     * seguro al combinarse con JOIN (valuedGroupings agrega categorias/ubicaciones
     * que también tienen created_at, evitando SQL ambiguo).
     *
     * Los estados se incluyen por WHITELIST explícita (no "!= VENDIDO"), de modo
     * que estados futuros no entren automáticamente en esta valuación.
     */
    private function inventoryValuedQuery(array $filters): Builder
    {
        $codigo = $filters['codigo'] ?? '';
        $marca = $filters['marca'] ?? '';
        $modelo = $filters['modelo'] ?? '';
        $serie = $filters['serie'] ?? '';
        $estado = $filters['estado'] ?? null;
        $ubicacionId = $filters['ubicacion_id'] ?? null;
        $categoriaId = $filters['categoria_id'] ?? null;
        $altaDesde = $filters['alta_desde'] ?? null;
        $altaHasta = $filters['alta_hasta'] ?? null;
        $estadoPrecio = $filters['estado_precio'] ?? '';
        $precioMin = $filters['precio_min'] ?? null;
        $precioMax = $filters['precio_max'] ?? null;

        $q = Item::query()
            ->with(['ubicacion:id,nombre', 'categoria:id,nombre'])
            ->whereIn('items.estado', self::ESTADOS_VALUADO)
            ->when($codigo !== '', fn (Builder $qq) => $qq->where('items.codigo', 'ilike', "%{$codigo}%"))
            ->when($marca !== '', fn (Builder $qq) => $qq->where('items.marca', 'ilike', "%{$marca}%"))
            ->when($modelo !== '', fn (Builder $qq) => $qq->where('items.modelo', 'ilike', "%{$modelo}%"))
            ->when($serie !== '', fn (Builder $qq) => $qq->where('items.serie', 'ilike', "%{$serie}%"))
            ->when($estado, fn (Builder $qq) => $qq->where('items.estado', $estado))
            ->when($ubicacionId, fn (Builder $qq) => $qq->where('items.ubicacion_id', $ubicacionId))
            ->when($categoriaId, fn (Builder $qq) => $qq->where('items.categoria_id', $categoriaId))
            ->when($altaDesde, fn (Builder $qq) => $qq->where('items.created_at', '>=', $altaDesde->copy()->startOfDay()))
            ->when($altaHasta, fn (Builder $qq) => $qq->where('items.created_at', '<=', $altaHasta->copy()->endOfDay()))
            ->when($estadoPrecio === 'con_precio', fn (Builder $qq) => $qq->whereNotNull('items.precio'))
            ->when($estadoPrecio === 'sin_precio', fn (Builder $qq) => $qq->whereNull('items.precio'))
            ->when($estadoPrecio === 'precio_cero', fn (Builder $qq) => $qq->where('items.precio', 0))
            ->when($precioMin !== null, fn (Builder $qq) => $qq->where('items.precio', '>=', $precioMin))
            ->when($precioMax !== null, fn (Builder $qq) => $qq->where('items.precio', '<=', $precioMax));

        return $q;
    }

    /**
     * KPIs de valuación calculados sobre el query ya filtrado (agregados SQL exactos).
     */
    private function valuedKpis(Builder $query): array
    {
        $agg = (clone $query)
            ->selectRaw('COUNT(*) AS equipos')
            ->selectRaw('COUNT(items.precio) AS con_precio')
            ->selectRaw('COUNT(*) FILTER (WHERE items.precio = 0) AS precio_cero')
            ->selectRaw('COALESCE(SUM(items.precio), 0) AS valor_comercial')
            ->selectRaw("COALESCE(SUM(items.precio) FILTER (WHERE items.estado IN ('DISPONIBLE','RESERVADO')), 0) AS valor_disponible_reservado")
            ->selectRaw("COALESCE(SUM(items.precio) FILTER (WHERE items.estado IN ('REPARACION','DEVUELTO')), 0) AS valor_revision")
            ->selectRaw("COALESCE(SUM(items.precio) FILTER (WHERE items.estado = 'BAJA'), 0) AS valor_baja")
            ->first();

        $equipos = (int) $agg->equipos;
        $conPrecio = (int) $agg->con_precio;
        $sinPrecio = max(0, $equipos - $conPrecio);
        $precioCero = (int) $agg->precio_cero;

        return [
            'equipos' => $equipos,
            'con_precio' => $conPrecio,
            'sin_precio' => $sinPrecio,
            'precio_cero' => $precioCero,
            'cobertura' => $equipos > 0 ? $conPrecio / $equipos : 0.0,
            'valor_comercial' => $agg->valor_comercial,
            'valor_disponible_reservado' => $agg->valor_disponible_reservado,
            'valor_revision' => $agg->valor_revision,
            'valor_baja' => $agg->valor_baja,
        ];
    }

    private function valuedGroup(Builder $query, string $sqlLabel): array
    {
        $rows = (clone $query)
            ->selectRaw("{$sqlLabel} AS grupo")
            ->selectRaw('COUNT(*) AS equipos')
            ->selectRaw('COUNT(items.precio) AS con_precio')
            ->selectRaw('COALESCE(SUM(items.precio), 0) AS valor')
            ->groupBy('grupo')
            ->orderByDesc('equipos')
            ->get();

        return $rows->map(fn ($r) => [
            'grupo' => $r->grupo ?: 'Sin asignar',
            'equipos' => (int) $r->equipos,
            'con_precio' => (int) $r->con_precio,
            'valor' => $r->valor,
        ])->all();
    }

    private function valuedGroupings(Builder $query): array
    {
        $porEstado = $this->valuedGroup($query, 'items.estado');

        $porCategoria = (clone $query)
            ->leftJoin('categorias', 'categorias.id', '=', 'items.categoria_id')
            ->selectRaw('COALESCE(categorias.nombre, \'Sin categoría\') AS grupo')
            ->selectRaw('COUNT(items.id) AS equipos')
            ->selectRaw('COUNT(items.precio) AS con_precio')
            ->selectRaw('COALESCE(SUM(items.precio), 0) AS valor')
            ->groupBy('grupo')
            ->orderByDesc('equipos')
            ->get()
            ->map(fn ($r) => [
                'grupo' => $r->grupo,
                'equipos' => (int) $r->equipos,
                'con_precio' => (int) $r->con_precio,
                'valor' => $r->valor,
            ])
            ->all();

        $porUbicacion = (clone $query)
            ->leftJoin('ubicaciones', 'ubicaciones.id', '=', 'items.ubicacion_id')
            ->selectRaw('COALESCE(ubicaciones.nombre, \'Sin asignar\') AS grupo')
            ->selectRaw('COUNT(items.id) AS equipos')
            ->selectRaw('COUNT(items.precio) AS con_precio')
            ->selectRaw('COALESCE(SUM(items.precio), 0) AS valor')
            ->groupBy('grupo')
            ->orderByDesc('equipos')
            ->get()
            ->map(fn ($r) => [
                'grupo' => $r->grupo,
                'equipos' => (int) $r->equipos,
                'con_precio' => (int) $r->con_precio,
                'valor' => $r->valor,
            ])
            ->all();

        return [
            'estado' => $porEstado,
            'categoria' => $porCategoria,
            'ubicacion' => $porUbicacion,
        ];
    }

    public function inventoryValued(Request $request)
    {
        $filters = $this->inventoryValuedFilters($request);
        $base = $this->inventoryValuedQuery($filters);

        $items = (clone $base)->orderByDesc('id')->paginate(25)->withQueryString();
        $kpis = $this->valuedKpis($base);
        $agrupaciones = $this->valuedGroupings($base);
        $total = (clone $base)->count();

        return view('reports.inventory-valued', [
            'items' => $items,
            'total' => $total,
            'kpis' => $kpis,
            'agrupaciones' => $agrupaciones,
            'ubicaciones' => Ubicacion::orderBy('nombre')->get(),
            'categorias' => Categoria::orderBy('nombre')->get(),
            'estados' => self::ESTADOS_VALUADO,
            'filters' => $filters,
        ]);
    }

    public function inventoryValuedXlsx(Request $request)
    {
        $filters = $this->inventoryValuedFilters($request);
        $base = $this->inventoryValuedQuery($filters);
        $kpis = $this->valuedKpis($base);
        $agrupaciones = $this->valuedGroupings($base);

        return Excel::download(
            new InventoryValuedExport($filters, $kpis, $agrupaciones, $base),
            $this->filename('reports_inventory_valued', 'xlsx')
        );
    }

    public function inventoryValuedPdf(Request $request)
    {
        $filters = $this->inventoryValuedFilters($request);
        $base = $this->inventoryValuedQuery($filters);
        $kpis = $this->valuedKpis($base);
        $agrupaciones = $this->valuedGroupings($base);
        $items = (clone $base)->orderByDesc('id')->get();

        $pdf = Pdf::loadView('reports.pdf.inventory-valued', [
            'items' => $items,
            'kpis' => $kpis,
            'agrupaciones' => $agrupaciones,
            'filters' => $filters,
            'generatedAt' => now(),
        ])
            ->setPaper('a4', 'landscape')
            ->setOptions([
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => true,
                'dpi' => 96,
                'defaultFont' => 'DejaVu Sans',
            ]);

        return $pdf->download($this->filename('reports_inventory_valued', 'pdf'));
    }

    /*
     * ============== MOVIMIENTOS ==============
     */

    private function movimientosFiltersFromRequest(Request $request): array
    {
        $validated = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'usuario_id' => ['nullable', 'integer', 'exists:users,id'],
            'tipo' => ['nullable', Rule::in(self::TIPOS)],
            'codigo' => ['nullable', 'string', 'max:40'],
            'ubicacion_origen_id' => ['nullable', 'integer', 'exists:ubicaciones,id'],
            'ubicacion_destino_id' => ['nullable', 'integer', 'exists:ubicaciones,id'],
        ]);

        $desde = ! empty($validated['desde']) ? Carbon::parse($validated['desde']) : null;
        $hasta = ! empty($validated['hasta']) ? Carbon::parse($validated['hasta']) : null;

        if ($desde && $hasta && $hasta->lt($desde)) {
            throw ValidationException::withMessages([
                'hasta' => 'La fecha "hasta" debe ser posterior o igual a "desde".',
            ]);
        }

        $usuarioId = $validated['usuario_id'] ?? null;
        $origenId = $validated['ubicacion_origen_id'] ?? null;
        $destinoId = $validated['ubicacion_destino_id'] ?? null;

        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'usuario_id' => $usuarioId,
            'usuario_name' => $usuarioId ? User::query()->whereKey($usuarioId)->value('name') : null,
            'tipo' => $validated['tipo'] ?? null,
            'codigo' => trim((string) ($validated['codigo'] ?? '')),
            'ubicacion_origen_id' => $origenId,
            'ubicacion_origen_name' => $origenId ? Ubicacion::query()->whereKey($origenId)->value('nombre') : null,
            'ubicacion_destino_id' => $destinoId,
            'ubicacion_destino_name' => $destinoId ? Ubicacion::query()->whereKey($destinoId)->value('nombre') : null,
        ];
    }

    /**
     * Query base de movimientos con filtros (web + XLSX + PDF comparten esta lógica).
     */
    private function movimientosQuery(array $filters): Builder
    {
        $desde = $filters['desde'] ?? null;
        $hasta = $filters['hasta'] ?? null;
        $codigo = $filters['codigo'] ?? '';

        return Movimiento::query()
            ->with(['item:id,codigo', 'user:id,name', 'deUbicacion:id,nombre', 'aUbicacion:id,nombre'])
            ->when($desde, fn (Builder $qq) => $qq->where('created_at', '>=', $desde->copy()->startOfDay()))
            ->when($hasta, fn (Builder $qq) => $qq->where('created_at', '<=', $hasta->copy()->endOfDay()))
            ->when($filters['usuario_id'] ?? null, fn (Builder $qq) => $qq->where('user_id', $filters['usuario_id']))
            ->when($filters['tipo'] ?? null, fn (Builder $qq) => $qq->where('tipo', $filters['tipo']))
            ->when($codigo !== '', function (Builder $qq) use ($codigo) {
                $qq->whereHas('item', fn (Builder $itemQ) => $itemQ->where('codigo', 'ilike', "%{$codigo}%"));
            })
            ->when(
                $filters['ubicacion_origen_id'] ?? null,
                fn (Builder $qq) => $qq->where('de_ubicacion_id', $filters['ubicacion_origen_id'])
            )
            ->when(
                $filters['ubicacion_destino_id'] ?? null,
                fn (Builder $qq) => $qq->where('a_ubicacion_id', $filters['ubicacion_destino_id'])
            );
    }

    public function movimientos(Request $request)
    {
        $filters = $this->movimientosFiltersFromRequest($request);
        $base = $this->movimientosQuery($filters);

        $movimientos = (clone $base)->paginate(25)->withQueryString();
        $total = (clone $base)->count();

        return view('reports.movimientos', [
            'movimientos' => $movimientos,
            'total' => $total,
            'usuarios' => User::orderBy('name')->get(),
            'tipos' => self::TIPOS,
            'ubicaciones' => Ubicacion::orderBy('nombre')->get(),
            'filters' => $filters,
        ]);
    }

    public function movimientosXlsx(Request $request)
    {
        $filters = $this->movimientosFiltersFromRequest($request);
        $query = $this->movimientosQuery($filters);

        return Excel::download(new MovimientosExport($query), $this->filename('reports_movimientos', 'xlsx'));
    }

    public function movimientosPdf(Request $request)
    {
        $filters = $this->movimientosFiltersFromRequest($request);
        $movimientos = $this->movimientosQuery($filters)->get();

        $pdf = Pdf::loadView('reports.pdf.movimientos', [
            'movimientos' => $movimientos,
            'filters' => $filters,
            'generatedAt' => now(),
        ])
            ->setPaper('a4', 'landscape')
            ->setOptions([
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => true,
                'dpi' => 96,
                'defaultFont' => 'DejaVu Sans',
            ]);

        return $pdf->download($this->filename('reports_movimientos', 'pdf'));
    }

    private function filename(string $prefix, string $ext): string
    {
        return $prefix.'_'.now()->format('Ymd_His').'.'.$ext;
    }
}
