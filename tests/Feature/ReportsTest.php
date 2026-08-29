<?php

use App\Exports\MovimientosExport;
use App\Exports\ReportInventoryExport;
use App\Models\Categoria;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\Ubicacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([
        'reportes.ver',
        'items.ver',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/*
 * Permisos
 */

it('bloquea la sección reportes a un usuario sin reportes.ver', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/reports')
        ->assertForbidden();
});

it('permite la sección reportes a un usuario con reportes.ver', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    $this->actingAs($user)
        ->get('/reports')
        ->assertOk()
        ->assertSee('Inventario')
        ->assertSee('Movimientos');
});

it('el rol Auditor consulta reportes sin poder modificar inventario', function () {
    Role::findOrCreate('Auditor', 'web')->givePermissionTo('reportes.ver', 'items.ver');

    $user = User::factory()->create();
    $user->assignRole('Auditor');

    expect($user->can('reportes.ver'))->toBeTrue();
    expect($user->can('items.crear'))->toBeFalse();
    expect($user->can('items.editar'))->toBeFalse();
    expect($user->can('items.mover'))->toBeFalse();
    expect($user->can('items.cambiar_estado'))->toBeFalse();

    $this->actingAs($user)
        ->get(route('reports.inventory'))
        ->assertOk();
});

it('bloquea el reporte de inventario a un usuario sin reportes.ver', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports.inventory'))
        ->assertForbidden();
});

/*
 * Inventario
 */

it('muestra todos los items del inventario', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    $a = Item::create(['codigo' => 'ITM-000001', 'estado' => 'DISPONIBLE']);
    $b = Item::create(['codigo' => 'ITM-000002', 'estado' => 'VENDIDO']);

    $this->actingAs($user)
        ->get(route('reports.inventory'))
        ->assertOk()
        ->assertSee($a->codigo)
        ->assertSee($b->codigo);
});

it('filtra inventario por estado', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    $a = Item::create(['codigo' => 'ITM-000003', 'estado' => 'DISPONIBLE']);
    $b = Item::create(['codigo' => 'ITM-000004', 'estado' => 'BAJA']);

    $this->actingAs($user)
        ->get(route('reports.inventory', ['estado' => 'BAJA']))
        ->assertOk()
        ->assertSee($b->codigo)
        ->assertDontSee($a->codigo);
});

it('filtra inventario por ubicación', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    $u1 = Ubicacion::create(['nombre' => 'Almacén']);
    $u2 = Ubicacion::create(['nombre' => 'Taller']);

    $a = Item::create(['codigo' => 'ITM-000005', 'estado' => 'DISPONIBLE', 'ubicacion_id' => $u1->id]);
    $b = Item::create(['codigo' => 'ITM-000006', 'estado' => 'DISPONIBLE', 'ubicacion_id' => $u2->id]);

    $this->actingAs($user)
        ->get(route('reports.inventory', ['ubicacion_id' => $u1->id]))
        ->assertOk()
        ->assertSee($a->codigo)
        ->assertDontSee($b->codigo);
});

it('filtra inventario por categoría', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    $c1 = Categoria::create(['nombre' => 'Laptop']);
    $c2 = Categoria::create(['nombre' => 'Monitor']);

    $a = Item::create(['codigo' => 'ITM-000007', 'estado' => 'DISPONIBLE', 'categoria_id' => $c1->id]);
    $b = Item::create(['codigo' => 'ITM-000008', 'estado' => 'DISPONIBLE', 'categoria_id' => $c2->id]);

    $this->actingAs($user)
        ->get(route('reports.inventory', ['categoria_id' => $c2->id]))
        ->assertOk()
        ->assertSee($b->codigo)
        ->assertDontSee($a->codigo);
});

it('filtra inventario por código', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    $a = Item::create(['codigo' => 'ITM-000009', 'estado' => 'DISPONIBLE']);
    $b = Item::create(['codigo' => 'ITM-000010', 'estado' => 'DISPONIBLE']);

    $this->actingAs($user)
        ->get(route('reports.inventory', ['codigo' => 'ITM-000009']))
        ->assertOk()
        ->assertSee($a->codigo)
        ->assertDontSee($b->codigo);
});

it('excluye items soft-deleted legacy del inventario', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    $a = Item::create(['codigo' => 'ITM-000011', 'estado' => 'DISPONIBLE']);
    $b = Item::create(['codigo' => 'ITM-000012', 'estado' => 'DISPONIBLE']);
    $b->delete();

    $this->actingAs($user)
        ->get(route('reports.inventory'))
        ->assertOk()
        ->assertSee($a->codigo)
        ->assertDontSee($b->codigo);
});

it('enlaza el código del inventario al detalle del item sin usar un ID visible', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver', 'items.ver');

    $item = Item::create(['codigo' => 'ITM-000013', 'estado' => 'DISPONIBLE']);

    $this->actingAs($user)
        ->get(route('reports.inventory'))
        ->assertOk()
        ->assertSee(route('items.show', $item))
        ->assertSee($item->codigo);
});

/*
 * XLSX / PDF Inventario
 */

it('exporta inventario XLSX respetando los filtros activos y sin exponer el ID interno', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    Item::create(['codigo' => 'ITM-000014', 'estado' => 'DISPONIBLE']);
    Item::create(['codigo' => 'ITM-000015', 'estado' => 'BAJA']);

    Excel::fake();

    $this->actingAs($user)
        ->get(route('reports.inventory.xlsx', ['estado' => 'BAJA']))
        ->assertOk();

    Excel::matchByRegex();
    Excel::assertDownloaded('#^reports_inventory_\d{8}_\d{6}\.xlsx$#', function (ReportInventoryExport $export) {
        $headings = $export->headings();

        if (($headings[0] ?? null) !== 'Código') {
            return false;
        }

        if (! in_array('Código', $headings, true)) {
            return false;
        }

        if (in_array('ID', $headings, true)) {
            return false;
        }

        return $export->query()->count() === 1;
    });
});

it('exporta inventario PDF respetando los filtros activos', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    Item::create(['codigo' => 'ITM-000016', 'estado' => 'DISPONIBLE']);
    $baja = Item::create(['codigo' => 'ITM-000017', 'estado' => 'BAJA']);

    $response = $this->actingAs($user)
        ->get(route('reports.inventory.pdf', ['estado' => 'BAJA']));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
    expect($response->headers->get('content-disposition'))->toContain('reports_inventory_');
    expect($response->headers->get('content-disposition'))->toContain('.pdf');
});

it('filtra inventario por rango de fecha de alta', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    $a = Item::create(['codigo' => 'ITM-000026', 'estado' => 'DISPONIBLE']);
    $a->created_at = Carbon::parse('2026-08-05 10:00:00');
    $a->save();

    $b = Item::create(['codigo' => 'ITM-000027', 'estado' => 'DISPONIBLE']);
    $b->created_at = Carbon::parse('2026-08-25 10:00:00');
    $b->save();

    $this->actingAs($user)
        ->get(route('reports.inventory', [
            'alta_desde' => '2026-08-01',
            'alta_hasta' => '2026-08-15',
        ]))
        ->assertOk()
        ->assertSee($a->codigo)
        ->assertDontSee($b->codigo);
});

it('valida el rango de fecha de alta', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    $this->actingAs($user)
        ->from(route('reports.inventory'))
        ->get(route('reports.inventory', [
            'alta_desde' => '2026-08-20',
            'alta_hasta' => '2026-08-10',
        ]))
        ->assertSessionHasErrors('alta_hasta')
        ->assertRedirect(route('reports.inventory'));
});

it('exporta inventario XLSX respetando el rango de fecha de alta', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    $a = Item::create(['codigo' => 'ITM-000028', 'estado' => 'DISPONIBLE']);
    $a->created_at = Carbon::parse('2026-08-05 10:00:00');
    $a->save();

    $b = Item::create(['codigo' => 'ITM-000029', 'estado' => 'DISPONIBLE']);
    $b->created_at = Carbon::parse('2026-08-25 10:00:00');
    $b->save();

    Excel::fake();

    $this->actingAs($user)
        ->get(route('reports.inventory.xlsx', [
            'alta_desde' => '2026-08-01',
            'alta_hasta' => '2026-08-15',
        ]))
        ->assertOk();

    Excel::matchByRegex();
    Excel::assertDownloaded('#^reports_inventory_\d{8}_\d{6}\.xlsx$#', fn (ReportInventoryExport $export) => $export->query()->count() === 1);
});

it('exporta inventario PDF usando los filtros compartidos de fecha de alta', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    Item::create(['codigo' => 'ITM-000030', 'estado' => 'DISPONIBLE']);

    $response = $this->actingAs($user)
        ->get(route('reports.inventory.pdf', [
            'alta_desde' => '2026-08-01',
            'alta_hasta' => '2026-08-15',
        ]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
    expect($response->headers->get('content-disposition'))->toContain('reports_inventory_');
    expect($response->headers->get('content-disposition'))->toContain('.pdf');
});

/*
 * Movimientos
 */

it('filtra movimientos por periodo de forma inclusiva', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    $item = Item::create(['codigo' => 'ITM-000018', 'estado' => 'DISPONIBLE']);

    $today = Carbon::parse('2026-08-15 10:00:00');
    $old = Carbon::parse('2026-05-01 10:00:00');

    $mk = function (string $tipo, string $notas, Carbon $at) use ($item, $user) {
        $m = new Movimiento([
            'item_id' => $item->id,
            'user_id' => $user->id,
            'tipo' => $tipo,
            'a_estado' => 'DISPONIBLE',
            'notas' => $notas,
        ]);
        $m->created_at = $at;
        $m->save();

        return $m;
    };

    $mk('ALTA', 'marca-en-periodo', $today);
    $mk('BAJA', 'marca-fuera-de-rango', $old);

    $this->actingAs($user)
        ->get(route('reports.movimientos', [
            'desde' => '2026-08-01',
            'hasta' => '2026-08-31',
        ]))
        ->assertOk()
        ->assertSee('marca-en-periodo')
        ->assertDontSee('marca-fuera-de-rango');
});

it('valida que hasta sea posterior o igual a desde', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    $this->actingAs($user)
        ->from(route('reports.movimientos'))
        ->get(route('reports.movimientos', ['desde' => '2026-08-20', 'hasta' => '2026-08-01']))
        ->assertSessionHasErrors('hasta')
        ->assertRedirect(route('reports.movimientos'));
});

it('filtra movimientos por usuario', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('reportes.ver');

    $other = User::factory()->create();

    $item = Item::create(['codigo' => 'ITM-000019', 'estado' => 'DISPONIBLE']);

    Movimiento::create([
        'item_id' => $item->id,
        'user_id' => $actor->id,
        'tipo' => 'ALTA',
        'a_estado' => 'DISPONIBLE',
        'notas' => 'movimiento-de-actor',
    ]);

    Movimiento::create([
        'item_id' => $item->id,
        'user_id' => $other->id,
        'tipo' => 'TRASLADO',
        'a_estado' => 'DISPONIBLE',
        'notas' => 'movimiento-de-otro',
    ]);

    $this->actingAs($actor)
        ->get(route('reports.movimientos', ['usuario_id' => $actor->id]))
        ->assertOk()
        ->assertSee('movimiento-de-actor')
        ->assertDontSee('movimiento-de-otro');
});

it('filtra movimientos por tipo', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    $item = Item::create(['codigo' => 'ITM-000020', 'estado' => 'DISPONIBLE']);

    Movimiento::create([
        'item_id' => $item->id,
        'user_id' => $user->id,
        'tipo' => 'BAJA',
        'de_estado' => 'DISPONIBLE',
        'a_estado' => 'BAJA',
        'notas' => 'marca-tipo-baja',
    ]);

    Movimiento::create([
        'item_id' => $item->id,
        'user_id' => $user->id,
        'tipo' => 'TRASLADO',
        'a_estado' => 'DISPONIBLE',
        'notas' => 'marca-tipo-traslado',
    ]);

    $this->actingAs($user)
        ->get(route('reports.movimientos', ['tipo' => 'BAJA']))
        ->assertOk()
        ->assertSee('marca-tipo-baja')
        ->assertDontSee('marca-tipo-traslado');
});

it('filtra movimientos por código de item', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    $itemA = Item::create(['codigo' => 'ITM-000021', 'estado' => 'DISPONIBLE']);
    $itemB = Item::create(['codigo' => 'ITM-000022', 'estado' => 'DISPONIBLE']);

    Movimiento::create([
        'item_id' => $itemA->id,
        'user_id' => $user->id,
        'tipo' => 'ALTA',
        'a_estado' => 'DISPONIBLE',
        'notas' => 'marca-item-a',
    ]);

    Movimiento::create([
        'item_id' => $itemB->id,
        'user_id' => $user->id,
        'tipo' => 'ALTA',
        'a_estado' => 'DISPONIBLE',
        'notas' => 'marca-item-b',
    ]);

    $this->actingAs($user)
        ->get(route('reports.movimientos', ['codigo' => 'ITM-000022']))
        ->assertOk()
        ->assertSee('marca-item-b')
        ->assertDontSee('marca-item-a');
});

/*
 * XLSX / PDF Movimientos
 */

it('exporta movimientos XLSX respetando los filtros activos', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    $item = Item::create(['codigo' => 'ITM-000023', 'estado' => 'DISPONIBLE']);

    Movimiento::create([
        'item_id' => $item->id,
        'user_id' => $user->id,
        'tipo' => 'ALTA',
        'a_estado' => 'DISPONIBLE',
    ]);

    Movimiento::create([
        'item_id' => $item->id,
        'user_id' => $user->id,
        'tipo' => 'TRASLADO',
        'a_estado' => 'DISPONIBLE',
    ]);

    Excel::fake();

    $this->actingAs($user)
        ->get(route('reports.movimientos.xlsx', ['tipo' => 'TRASLADO']))
        ->assertOk();

    Excel::matchByRegex();
    Excel::assertDownloaded('#^reports_movimientos_\d{8}_\d{6}\.xlsx$#', fn (MovimientosExport $export) => $export->query()->count() === 1);
});

it('exporta movimientos PDF respetando los filtros activos', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('reportes.ver');

    $item = Item::create(['codigo' => 'ITM-000024', 'estado' => 'DISPONIBLE']);

    Movimiento::create([
        'item_id' => $item->id,
        'user_id' => $user->id,
        'tipo' => 'ALTA',
        'a_estado' => 'DISPONIBLE',
        'notas' => 'marca-pdf-alta',
    ]);

    Movimiento::create([
        'item_id' => $item->id,
        'user_id' => $user->id,
        'tipo' => 'BAJA',
        'de_estado' => 'DISPONIBLE',
        'a_estado' => 'BAJA',
        'notas' => 'marca-pdf-baja',
    ]);

    $response = $this->actingAs($user)
        ->get(route('reports.movimientos.pdf', ['tipo' => 'BAJA']));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
    expect($response->headers->get('content-disposition'))->toContain('reports_movimientos_');
    expect($response->headers->get('content-disposition'))->toContain('.pdf');
});

/*
 * Historial completo de Item (proyección en items.show)
 */

it('el historial del item muestra ALTA, TRASLADO y CAMBIO_ESTADO con usuario y orden coherente', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    $ubicacionA = Ubicacion::create(['nombre' => 'Almacén']);
    $ubicacionB = Ubicacion::create(['nombre' => 'Taller']);

    $item = Item::create([
        'codigo' => 'ITM-000025',
        'estado' => 'REPARACION',
        'ubicacion_id' => $ubicacionB->id,
    ]);

    $t0 = Carbon::parse('2026-08-01 09:00:00');
    $t1 = Carbon::parse('2026-08-02 09:00:00');
    $t2 = Carbon::parse('2026-08-03 09:00:00');

    $mk = function (array $data, Carbon $at) use ($item, $user) {
        $m = new Movimiento($data + ['item_id' => $item->id, 'user_id' => $user->id]);
        $m->created_at = $at;
        $m->save();

        return $m;
    };

    $mk([
        'tipo' => 'ALTA',
        'a_estado' => 'DISPONIBLE',
        'a_ubicacion_id' => $ubicacionA->id,
        'notas' => 'Alta de item',
    ], $t0);

    $mk([
        'tipo' => 'TRASLADO',
        'de_estado' => 'DISPONIBLE',
        'a_estado' => 'DISPONIBLE',
        'de_ubicacion_id' => $ubicacionA->id,
        'a_ubicacion_id' => $ubicacionB->id,
        'notas' => 'Movimiento de ubicación',
    ], $t1);

    $mk([
        'tipo' => 'CAMBIO_ESTADO',
        'de_estado' => 'DISPONIBLE',
        'a_estado' => 'REPARACION',
        'de_ubicacion_id' => $ubicacionB->id,
        'a_ubicacion_id' => $ubicacionB->id,
        'notas' => 'Cambio de estado',
        'evidencia_path' => 'movimientos/evidencia.pdf',
    ], $t2);

    $html = $this->actingAs($user)
        ->get(route('items.show', $item))
        ->assertOk()
        ->assertSee('ALTA')
        ->assertSee('TRASLADO')
        ->assertSee('CAMBIO_ESTADO')
        ->assertSee($user->name)
        ->assertSee('Ver evidencia')
        ->getContent();

    $posCambio = strpos($html, 'CAMBIO_ESTADO');
    $posTraslado = strpos($html, 'TRASLADO');
    $posAlta = strpos($html, 'ALTA');

    expect($posCambio)->not->toBeFalse();
    expect($posTraslado)->not->toBeFalse();
    expect($posAlta)->not->toBeFalse();
    expect($posCambio)->toBeLessThan($posTraslado);
    expect($posTraslado)->toBeLessThan($posAlta);
});
