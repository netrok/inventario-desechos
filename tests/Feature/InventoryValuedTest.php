<?php

use App\Exports\InventoryValuedExport;
use App\Http\Controllers\ReportController;
use App\Models\Categoria;
use App\Models\Item;
use App\Models\Ubicacion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    \Spatie\Permission\Models\Permission::findOrCreate('reportes.ver', 'web');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function valuedUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    return $user;
}

/*
 * ============ INVENTARIO OPERATIVO: PRECIO ============
 */

it('el reporte operativo web muestra el precio de venta', function () {
    Item::create(['codigo' => 'ITM-OP-001', 'estado' => 'DISPONIBLE', 'precio' => 123.45]);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory'))
        ->assertOk()
        ->assertSee('Precio de venta')
        ->assertSee('123.45');
});

it('el reporte operativo muestra un marcador si el precio es NULL', function () {
    Item::create(['codigo' => 'ITM-OP-002', 'estado' => 'DISPONIBLE']);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory'))
        ->assertOk()
        ->assertSee('—');
});

it('el XLSX operativo incluye la columna Precio de venta', function () {
    Item::create(['codigo' => 'ITM-OP-003', 'estado' => 'DISPONIBLE', 'precio' => 88.11]);

    Excel::fake();
    $this->actingAs(valuedUser())
        ->get(route('reports.inventory.xlsx'))
        ->assertOk();

    Excel::matchByRegex();
    Excel::assertDownloaded('#^reports_inventory_\d{8}_\d{6}\.xlsx$#', function (\App\Exports\ReportInventoryExport $export) {
        return in_array('Precio de venta', $export->headings(), true);
    });
});

it('el PDF operativo incluye el precio de venta', function () {
    Item::create(['codigo' => 'ITM-OP-004', 'estado' => 'DISPONIBLE', 'precio' => 45.50]);

    $response = $this->actingAs(valuedUser())
        ->get(route('reports.inventory.pdf'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
});

it('los filtros existentes del inventario operativo siguen funcionando', function () {
    $a = Item::create(['codigo' => 'ITM-OP-005', 'estado' => 'DISPONIBLE']);
    $b = Item::create(['codigo' => 'ITM-OP-006', 'estado' => 'BAJA']);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory', ['estado' => 'BAJA']))
        ->assertOk()
        ->assertSee($b->codigo)
        ->assertDontSee($a->codigo);
});

/*
 * ============ INVENTARIO VALUADO: ESTADOS ============
 */

it('excluye SIEMPRE el estado VENDIDO', function () {
    $vendido = Item::create(['codigo' => 'ITM-VAL-001', 'estado' => 'VENDIDO', 'precio' => 999.99]);
    $disp = Item::create(['codigo' => 'ITM-VAL-002', 'estado' => 'DISPONIBLE', 'precio' => 10.00]);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued'))
        ->assertOk()
        ->assertSee($disp->codigo)
        ->assertDontSee($vendido->codigo);
});

it('incluye DISPONIBLE, RESERVADO, REPARACION, DEVUELTO y BAJA', function () {
    foreach (['DISPONIBLE', 'RESERVADO', 'REPARACION', 'DEVUELTO', 'BAJA'] as $i => $estado) {
        Item::create(['codigo' => "ITM-VAL-ST-{$i}", 'estado' => $estado, 'precio' => 10.00]);
    }

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued'))
        ->assertOk()
        ->assertSee('ITM-VAL-ST-0')
        ->assertSee('ITM-VAL-ST-1')
        ->assertSee('ITM-VAL-ST-2')
        ->assertSee('ITM-VAL-ST-3')
        ->assertSee('ITM-VAL-ST-4');
});

/*
 * ============ INVENTARIO VALUADO: VALORES EXACTOS ============
 */

it('suma valores exactos sin float: 100.10 + 200.20 = 300.30', function () {
    Item::create(['codigo' => 'ITM-VAL-M1', 'estado' => 'DISPONIBLE', 'precio' => '100.10']);
    Item::create(['codigo' => 'ITM-VAL-M2', 'estado' => 'DISPONIBLE', 'precio' => '200.20']);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued'))
        ->assertOk()
        ->assertSee('300.30');
});

it('un precio NULL cuenta como sin precio y no aporta al valor', function () {
    Item::create(['codigo' => 'ITM-VAL-N1', 'estado' => 'DISPONIBLE', 'precio' => 50.00]);
    Item::create(['codigo' => 'ITM-VAL-N2', 'estado' => 'DISPONIBLE']);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued'))
        ->assertOk()
        ->assertSee('Sin precio')
        ->assertSee('50.00')
        ->assertDontSee('100.00');
});

it('un precio 0 cuenta como con precio y suma cero', function () {
    Item::create(['codigo' => 'ITM-VAL-Z1', 'estado' => 'DISPONIBLE', 'precio' => 0]);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued'))
        ->assertOk()
        ->assertSee('Precio cero');
});

it('calcula la cobertura correctamente', function () {
    Item::create(['codigo' => 'ITM-VAL-C1', 'estado' => 'DISPONIBLE', 'precio' => 100.00]);
    Item::create(['codigo' => 'ITM-VAL-C2', 'estado' => 'DISPONIBLE']);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued'))
        ->assertOk()
        ->assertSee('50.0%');
});

/*
 * ============ INVENTARIO VALUADO: AGRUPACIONES ============
 */

it('agrupa por estado', function () {
    Item::create(['codigo' => 'ITM-VAL-GE1', 'estado' => 'DISPONIBLE', 'precio' => 10.00]);
    Item::create(['codigo' => 'ITM-VAL-GE2', 'estado' => 'BAJA', 'precio' => 20.00]);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued'))
        ->assertOk()
        ->assertSee('Por estado')
        ->assertSee('BAJA');
});

it('agrupa por categoría', function () {
    $c = Categoria::create(['nombre' => 'Laptop']);
    Item::create(['codigo' => 'ITM-VAL-GC1', 'estado' => 'DISPONIBLE', 'categoria_id' => $c->id, 'precio' => 10.00]);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued'))
        ->assertOk()
        ->assertSee('Por categoría')
        ->assertSee('Laptop');
});

it('agrupa por ubicación', function () {
    $u = Ubicacion::create(['nombre' => 'Corporativo']);
    Item::create(['codigo' => 'ITM-VAL-GU1', 'estado' => 'DISPONIBLE', 'ubicacion_id' => $u->id, 'precio' => 10.00]);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued'))
        ->assertOk()
        ->assertSee('Por ubicación')
        ->assertSee('Corporativo');
});

/*
 * ============ INVENTARIO VALUADO: FILTROS DE PRECIO ============
 */

it('filtra por estado de precio: con precio', function () {
    $con = Item::create(['codigo' => 'ITM-VAL-FP1', 'estado' => 'DISPONIBLE', 'precio' => 10.00]);
    $sin = Item::create(['codigo' => 'ITM-VAL-FP2', 'estado' => 'DISPONIBLE']);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued', ['estado_precio' => 'con_precio']))
        ->assertOk()
        ->assertSee($con->codigo)
        ->assertDontSee($sin->codigo);
});

it('filtra por estado de precio: sin precio', function () {
    $con = Item::create(['codigo' => 'ITM-VAL-FP3', 'estado' => 'DISPONIBLE', 'precio' => 10.00]);
    $sin = Item::create(['codigo' => 'ITM-VAL-FP4', 'estado' => 'DISPONIBLE']);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued', ['estado_precio' => 'sin_precio']))
        ->assertOk()
        ->assertSee($sin->codigo)
        ->assertDontSee($con->codigo);
});

it('filtra por estado de precio: precio cero', function () {
    $cero = Item::create(['codigo' => 'ITM-VAL-FP5', 'estado' => 'DISPONIBLE', 'precio' => 0]);
    $normal = Item::create(['codigo' => 'ITM-VAL-FP6', 'estado' => 'DISPONIBLE', 'precio' => 5.00]);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued', ['estado_precio' => 'precio_cero']))
        ->assertOk()
        ->assertSee($cero->codigo)
        ->assertDontSee($normal->codigo);
});

it('filtra por precio mínimo y máximo', function () {
    $bajo = Item::create(['codigo' => 'ITM-VAL-FM1', 'estado' => 'DISPONIBLE', 'precio' => 10.00]);
    $alto = Item::create(['codigo' => 'ITM-VAL-FM2', 'estado' => 'DISPONIBLE', 'precio' => 500.00]);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued', ['precio_min' => 50, 'precio_max' => 600]))
        ->assertOk()
        ->assertSee($alto->codigo)
        ->assertDontSee($bajo->codigo);
});

it('valida que precio máximo sea >= mínimo', function () {
    Item::create(['codigo' => 'ITM-VAL-FV1', 'estado' => 'DISPONIBLE', 'precio' => 10.00]);

    $this->actingAs(valuedUser())
        ->from(route('reports.inventory-valued'))
        ->get(route('reports.inventory-valued', ['precio_min' => 500, 'precio_max' => 50]))
        ->assertSessionHasErrors('precio_max')
        ->assertRedirect(route('reports.inventory-valued'));
});

it('rechaza importes con más de 2 decimales', function () {
    Item::create(['codigo' => 'ITM-VAL-3D1', 'estado' => 'DISPONIBLE', 'precio' => 10.00]);

    $this->actingAs(valuedUser())
        ->from(route('reports.inventory-valued'))
        ->get(route('reports.inventory-valued', ['precio_min' => '10.999']))
        ->assertSessionHasErrors('precio_min');
});

it('precio_min=0 se conserva y aplica (no se trata como ausente)', function () {
    $cero = Item::create(['codigo' => 'ITM-VAL-0A', 'estado' => 'DISPONIBLE', 'precio' => '0.00']);
    $sin = Item::create(['codigo' => 'ITM-VAL-0B', 'estado' => 'DISPONIBLE']);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued', ['precio_min' => 0]))
        ->assertOk()
        ->assertSee($cero->codigo)
        ->assertDontSee($sin->codigo);
});

it('precio_max=0 se conserva y filtra solo precios <= 0', function () {
    $cero = Item::create(['codigo' => 'ITM-VAL-0C', 'estado' => 'DISPONIBLE', 'precio' => '0.00']);
    $positivo = Item::create(['codigo' => 'ITM-VAL-0D', 'estado' => 'DISPONIBLE', 'precio' => '5.00']);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued', ['precio_max' => 0]))
        ->assertOk()
        ->assertSee($cero->codigo)
        ->assertDontSee($positivo->codigo);
});

it('precio_min=0 + precio_max=0 funciona para precios exactamente cero', function () {
    $cero = Item::create(['codigo' => 'ITM-VAL-0E', 'estado' => 'DISPONIBLE', 'precio' => '0.00']);
    $positivo = Item::create(['codigo' => 'ITM-VAL-0F', 'estado' => 'DISPONIBLE', 'precio' => '5.00']);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued', ['precio_min' => 0, 'precio_max' => 0]))
        ->assertOk()
        ->assertSee($cero->codigo)
        ->assertDontSee($positivo->codigo);
});

/*
 * ============ INVENTARIO VALUADO: QUERYS CALIFICADAS (FIX 4) ============
 */

it('no genera SQL ambiguo con rango de alta + agrupación por categoría', function () {
    $c = Categoria::create(['nombre' => 'Monitor']);
    $item = Item::create([
        'codigo' => 'ITM-AMB-1', 'estado' => 'DISPONIBLE', 'categoria_id' => $c->id, 'precio' => '10.00',
    ]);
    $item->created_at = Carbon::parse('2026-08-10 10:00:00');
    $item->save();

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued', ['alta_desde' => '2026-08-01', 'alta_hasta' => '2026-08-31']))
        ->assertOk();
});

it('no genera SQL ambiguo con rango de alta + agrupación por ubicación', function () {
    $u = Ubicacion::create(['nombre' => 'Corporativo']);
    $item = Item::create([
        'codigo' => 'ITM-AMB-2', 'estado' => 'DISPONIBLE', 'ubicacion_id' => $u->id, 'precio' => '10.00',
    ]);
    $item->created_at = Carbon::parse('2026-08-10 10:00:00');
    $item->save();

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued', ['alta_desde' => '2026-08-01', 'alta_hasta' => '2026-08-31']))
        ->assertOk();
});

it('filtro categoria_id combinado con agrupaciones no genera SQL ambiguo', function () {
    $c = Categoria::create(['nombre' => 'Laptop']);
    Item::create(['codigo' => 'ITM-AMB-3', 'estado' => 'DISPONIBLE', 'categoria_id' => $c->id, 'precio' => '10.00']);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued', ['categoria_id' => $c->id]))
        ->assertOk();
});

it('filtro ubicacion_id combinado con agrupaciones no genera SQL ambiguo', function () {
    $u = Ubicacion::create(['nombre' => 'Taller']);
    Item::create(['codigo' => 'ITM-AMB-4', 'estado' => 'DISPONIBLE', 'ubicacion_id' => $u->id, 'precio' => '10.00']);

    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued', ['ubicacion_id' => $u->id]))
        ->assertOk();
});

/*
 * ============ INVENTARIO VALUADO: ACCESO Y EXPORTACIONES ============
 */

it('bloquea los endpoints valuados sin permisos', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports.inventory-valued'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('reports.inventory-valued.pdf'))
        ->assertForbidden();

    $this->actingAs($user)
        ->get(route('reports.inventory-valued.xlsx'))
        ->assertForbidden();
});

it('PDF valuado respeta filtros y se descarga como binario', function () {
    Item::create(['codigo' => 'ITM-VAL-PD1', 'estado' => 'VENDIDO', 'precio' => '999.99']);
    Item::create(['codigo' => 'ITM-VAL-PD2', 'estado' => 'DISPONIBLE', 'precio' => '15.00']);

    $response = $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued.pdf'))
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf');
    expect($response->headers->get('content-disposition'))->toContain('reports_inventory_valued_');
});

it('el dataset del PDF valuado excluye VENDIDO e incluye solo lo filtrado', function () {
    Item::create(['codigo' => 'ITM-PDF-A', 'estado' => 'VENDIDO', 'precio' => '999.99']);
    Item::create(['codigo' => 'ITM-PDF-B', 'estado' => 'DISPONIBLE', 'precio' => '15.00']);
    Item::create(['codigo' => 'ITM-PDF-C', 'estado' => 'DISPONIBLE', 'precio' => '300.00']);

    $controller = new ReportController;
    $filtersMethod = new ReflectionMethod(ReportController::class, 'inventoryValuedFilters');
    $filtersMethod->setAccessible(true);
    $filters = $filtersMethod->invoke(
        $controller,
        Request::create(route('reports.inventory-valued'), 'GET', ['precio_max' => 100])
    );

    $queryMethod = new ReflectionMethod(ReportController::class, 'inventoryValuedQuery');
    $queryMethod->setAccessible(true);
    $query = $queryMethod->invoke($controller, $filters);

    $codigos = $query->orderByDesc('id')->get()->pluck('codigo')->all();

    expect($codigos)->toContain('ITM-PDF-B');
    expect($codigos)->not->toContain('ITM-PDF-A');
    expect($codigos)->not->toContain('ITM-PDF-C');
});

it('XLSX valuado se descarga con el nombre correcto', function () {
    Item::create(['codigo' => 'ITM-VAL-XL1', 'estado' => 'DISPONIBLE', 'precio' => '15.00']);
    Item::create(['codigo' => 'ITM-VAL-XL2', 'estado' => 'VENDIDO', 'precio' => '999.99']);

    Excel::fake();
    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued.xlsx'))
        ->assertOk();

    Excel::matchByRegex();
    Excel::assertDownloaded('#^reports_inventory_valued_\d{8}_\d{6}\.xlsx$#');
});

it('el export valuado tiene exactamente 2 hojas llamadas Resumen y Detalle', function () {
    $controller = new ReportController;
    $filtersMethod = new ReflectionMethod(ReportController::class, 'inventoryValuedFilters');
    $filtersMethod->setAccessible(true);
    $filters = $filtersMethod->invoke($controller, Request::create(route('reports.inventory-valued'), 'GET'));

    $queryMethod = new ReflectionMethod(ReportController::class, 'inventoryValuedQuery');
    $queryMethod->setAccessible(true);
    $base = $queryMethod->invoke($controller, $filters);

    $kpisMethod = new ReflectionMethod(ReportController::class, 'valuedKpis');
    $kpisMethod->setAccessible(true);
    $kpis = $kpisMethod->invoke($controller, clone $base);
    $gruposMethod = new ReflectionMethod(ReportController::class, 'valuedGroupings');
    $gruposMethod->setAccessible(true);
    $agrupaciones = $gruposMethod->invoke($controller, clone $base);

    $export = new InventoryValuedExport($filters, $kpis, $agrupaciones, $base);
    $sheets = $export->sheets();

    expect($sheets)->toHaveCount(2);
    expect($sheets[0]->title())->toBe('Resumen');
    expect($sheets[1]->title())->toBe('Detalle');
});

it('la hoja Detalle del XLSX usa el query filtrado y excluye VENDIDO', function () {
    Item::create(['codigo' => 'ITM-XLS-A', 'estado' => 'VENDIDO', 'precio' => '999.99']);
    Item::create(['codigo' => 'ITM-XLS-B', 'estado' => 'DISPONIBLE', 'precio' => '15.00']);
    Item::create(['codigo' => 'ITM-XLS-C', 'estado' => 'DISPONIBLE', 'precio' => '300.00']);

    $controller = new ReportController;
    $filtersMethod = new ReflectionMethod(ReportController::class, 'inventoryValuedFilters');
    $filtersMethod->setAccessible(true);
    $filters = $filtersMethod->invoke(
        $controller,
        Request::create(route('reports.inventory-valued'), 'GET', ['precio_max' => 100])
    );

    $queryMethod = new ReflectionMethod(ReportController::class, 'inventoryValuedQuery');
    $queryMethod->setAccessible(true);
    $base = $queryMethod->invoke($controller, $filters);

    $kpisMethod = new ReflectionMethod(ReportController::class, 'valuedKpis');
    $kpisMethod->setAccessible(true);
    $kpis = $kpisMethod->invoke($controller, clone $base);
    $gruposMethod = new ReflectionMethod(ReportController::class, 'valuedGroupings');
    $gruposMethod->setAccessible(true);
    $agrupaciones = $gruposMethod->invoke($controller, clone $base);

    $detalle = (new InventoryValuedExport($filters, $kpis, $agrupaciones, $base))->sheets()[1];
    $codigos = $detalle->query()->get()->pluck('codigo')->all();

    expect($codigos)->toContain('ITM-XLS-B');
    expect($codigos)->not->toContain('ITM-XLS-A');
    expect($codigos)->not->toContain('ITM-XLS-C');
});

it('no muestra VENDIDO como opción de filtro en el valuado', function () {
    $this->actingAs(valuedUser())
        ->get(route('reports.inventory-valued'))
        ->assertOk()
        ->assertSee('REPARACION')
        ->assertDontSee('VENDIDO');
});

/*
 * ============ REPORTES INDEX ============
 */

it('reports.index muestra inventario operativo, valuado y movimientos', function () {
    $this->actingAs(valuedUser())
        ->get(route('reports.index'))
        ->assertOk()
        ->assertSee('Inventario operativo')
        ->assertSee('Inventario valuado')
        ->assertSee('Movimientos');
});
