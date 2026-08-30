<?php

use App\Models\DocumentoPostventaDetalle;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\RevisionDevolucion;
use App\Models\User;
use App\Models\Venta;
use Database\Seeders\RolesAndAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([
        'items.ver',
        'items.editar',
        'items.cambiar_estado',
        'items.revisar_devolucion',
        'ventas.ver',
        'ventas.crear',
        'ventas.devolver',
        'dashboard.ver',
        'reportes.ver',
        'categorias.ver',
        'ubicaciones.ver',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () {
    Movimiento::flushEventListeners();
    app('events')->forget('eloquent.retrieved: '.Item::class);
});

function integRevisionista(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo([
        'items.ver',
        'items.revisar_devolucion',
        'ventas.ver',
        'ventas.crear',
        'ventas.devolver',
        'dashboard.ver',
        'reportes.ver',
    ]);

    return $user;
}

function integEscenario(float $precio = 150.0): array
{
    $vendedor = User::factory()->create();
    $item = Item::create(['estado' => 'VENDIDO', 'precio' => $precio]);

    $venta = Venta::create([
        'user_id' => $vendedor->id,
        'total' => \App\Support\Money::aPrecio(\App\Support\Money::aCentavos($precio)),
        'forma_pago' => 'EFECTIVO',
    ]);

    $detalleVenta = $venta->detalles()->create(['item_id' => $item->id, 'precio' => $precio]);
    $item->update(['estado' => 'VENDIDO']);

    // La devolución es un acto transaccional del usuario autenticado.
    test()->actingAs($vendedor);
    openCajaFor($vendedor);

    $doc = app(\App\Services\PostventaService::class)->devolver(
        $venta,
        [$detalleVenta->id],
        'El cliente devolvió el equipo.',
        'EFECTIVO'
    );

    $detalleDevolucion = DocumentoPostventaDetalle::query()->where('item_id', $item->id)->firstOrFail();

    return compact('item', 'venta', 'doc', 'detalleDevolucion');
}

/*
 * =========================
 * Vista y flujo HTTP de revisión
 * =========================
 */

it('muestra el formulario de revisión con el contexto completo de la devolución', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $this->actingAs($user)
        ->get(route('items.revision', $esc['detalleDevolucion']))
        ->assertOk()
        ->assertSee('Revisión de artículo devuelto')
        ->assertSee($esc['item']->codigo)
        ->assertSee('DEVUELTO')
        ->assertSee($esc['doc']->folio)
        ->assertSee('El cliente devolvió el equipo.')
        ->assertSee('Apto para venta')
        ->assertSee('Requerirá reparación')
        ->assertSee('No recuperable (baja)');
});

it('el formulario de revisión no expone campos de importe ni de motivo editables', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $html = $this->actingAs($user)
        ->get(route('items.revision', $esc['detalleDevolucion']))
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('name="importe"')
        ->and($html)->not->toContain('name="motivo"');
});

it('rechaza abrir la revisión si el item ya no está DEVUELTO', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $esc['item']->update(['estado' => 'DISPONIBLE']);

    $this->actingAs($user)
        ->get(route('items.revision', $esc['detalleDevolucion']))
        ->assertStatus(409);
});

it('POST DISPONIBLE reincorpora, redirige al item y confirma el resultado', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $this->actingAs($user)
        ->post(route('items.revision.store', $esc['detalleDevolucion']), [
            'resultado' => 'DISPONIBLE',
        ])
        ->assertRedirect(route('items.show', $esc['item']->id))
        ->assertSessionHas('success', 'Revisión registrada. El artículo está disponible nuevamente para venta.');

    expect(RevisionDevolucion::query()->where('resultado', 'DISPONIBLE')->count())->toBe(1);
});

it('POST REPARACION confirma el envío a taller', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $this->actingAs($user)
        ->post(route('items.revision.store', $esc['detalleDevolucion']), [
            'resultado' => 'REPARACION',
            'observaciones' => 'Fuente sin energía.',
        ])
        ->assertRedirect(route('items.show', $esc['item']->id))
        ->assertSessionHas('success', 'Revisión registrada. El artículo fue enviado a reparación.');

    expect($esc['item']->refresh()->estado)->toBe('REPARACION');
});

it('POST BAJA confirma la baja del equipo', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $this->actingAs($user)
        ->post(route('items.revision.store', $esc['detalleDevolucion']), [
            'resultado' => 'BAJA',
        ])
        ->assertRedirect(route('items.show', $esc['item']->id))
        ->assertSessionHas('success', 'Revisión registrada. El artículo fue dado de baja.');

    expect($esc['item']->refresh()->estado)->toBe('BAJA');
});

it('POST con resultado inválido falla la validación y no muta el item', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $this->actingAs($user)
        ->post(route('items.revision.store', $esc['detalleDevolucion']), ['resultado' => 'EXTRAVIADO'])
        ->assertSessionHasErrors('resultado');

    expect($esc['item']->refresh()->estado)->toBe('DEVUELTO');
    expect(RevisionDevolucion::count())->toBe(0);
});

it('guardar observaciones opcionales desde el endpoint', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $this->actingAs($user)
        ->post(route('items.revision.store', $esc['detalleDevolucion']), [
            'resultado' => 'DISPONIBLE',
            'observaciones' => 'Equipo sin daños.',
        ])
        ->assertSessionHasNoErrors();

    expect(RevisionDevolucion::query()->first()->observaciones)->toBe('Equipo sin daños.');
});

it('el listado de items ofrece el botón Revisar para devoluciones pendientes', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $this->actingAs($user)
        ->get(route('items.index', ['estado' => 'DEVUELTO']))
        ->assertOk()
        ->assertSee($esc['item']->codigo)
        ->assertSee(route('items.revision', $esc['detalleDevolucion']));
});

it('el botón Revisar no aparece para items ya revisados', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $this->actingAs($user)
        ->post(route('items.revision.store', $esc['detalleDevolucion']), ['resultado' => 'DISPONIBLE'])
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->get(route('items.index'))
        ->assertOk()
        ->assertDontSee(route('items.revision', $esc['detalleDevolucion']));
});

it('la vista del item expone el botón Revisar artículo mientras está DEVUELTO', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $this->actingAs($user)
        ->get(route('items.show', $esc['item']))
        ->assertOk()
        ->assertSee('Revisar artículo')
        ->assertSee(route('items.revision', $esc['detalleDevolucion']));
});

it('al registrar una devolución el sistema avisa de artículos pendientes de revisión', function () {
    $vendedor = User::factory()->create();
    $item = Item::create(['estado' => 'VENDIDO', 'precio' => 60.0]);

    $venta = Venta::create([
        'user_id' => $vendedor->id,
        'total' => '60.00',
        'forma_pago' => 'EFECTIVO',
    ]);

    $detalleVenta = $venta->detalles()->create(['item_id' => $item->id, 'precio' => 60.0]);
    $item->update(['estado' => 'VENDIDO']);

    $user = integRevisionista();
    openCajaFor($user);

    $this->actingAs($user)
        ->post(route('ventas.devolver.store', $venta), [
            'motivo' => 'El cliente devuelve el equipo.',
            'forma_reembolso' => 'EFECTIVO',
            'detalles' => [$detalleVenta->id],
        ])
        ->assertRedirect()
        ->assertSessionHas('success')
        ->assertSessionHas('pendientesRevision');
});

it('el documento postventa muestra el aviso y el enlace a revisar artículos devueltos', function () {
    $esc = integEscenario();
    $user = integRevisionista();
    $user->givePermissionTo('items.revisar_devolucion');

    $this->withSession(['pendientesRevision' => true])
        ->actingAs($user)
        ->get(route('postventa.show', $esc['doc']))
        ->assertOk()
        ->assertSee('Los artículos devueltos quedaron pendientes de revisión.')
        ->assertSee('Revisar artículos devueltos')
        ->assertSee(route('items.index', ['estado' => 'DEVUELTO']));
});

it('sin permiso de revisión el aviso del documento postventa se mantiene oculto', function () {
    $esc = integEscenario();
    $sinPermiso = User::factory()->create();
    $sinPermiso->givePermissionTo(['ventas.ver']);

    $this->withSession(['pendientesRevision' => true])
        ->actingAs($sinPermiso)
        ->get(route('postventa.show', $esc['doc']))
        ->assertOk()
        ->assertDontSee('Revisar artículos devueltos')
        ->assertDontSee(route('items.index', ['estado' => 'DEVUELTO']));
});

/*
 * =========================
 * Dashboard (B13)
 * =========================
 */

it('el dashboard muestra el KPI Devueltos pendientes con su conteo', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Devueltos pendientes')
        ->assertSee('1');
});

it('el KPI Devueltos pendientes enlaza al listado filtrado cuando el usuario puede ver items', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('items.index', ['estado' => 'DEVUELTO']));
});

it('sin items.ver el KPI se muestra sin enlace (solo lectura)', function () {
    $esc = integEscenario();
    $dashboard = User::factory()->create();
    $dashboard->givePermissionTo('dashboard.ver');

    $this->actingAs($dashboard)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Devueltos pendientes')
        ->assertDontSee(route('items.index', ['estado' => 'DEVUELTO']));
});

it('el enlace del KPI lleva al listado de items DEVUELTO con su contador', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $this->actingAs($user)
        ->get(route('items.index', ['estado' => 'DEVUELTO']))
        ->assertOk()
        ->assertSee($esc['item']->codigo)
        ->assertSee('DEVUELTO');
});

/*
 * =========================
 * Reportes (B13)
 * =========================
 */

it('el reporte de movimientos permite filtrar por REVISION_DEVOLUCION', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $this->actingAs($user)
        ->post(route('items.revision.store', $esc['detalleDevolucion']), ['resultado' => 'DISPONIBLE'])
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->get(route('reports.movimientos', ['tipo' => 'REVISION_DEVOLUCION']))
        ->assertOk()
        ->assertSee('REVISION_DEVOLUCION')
        ->assertSee($esc['item']->codigo);
});

it('el reporte de inventario lista los artículos devueltos pendientes', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $this->actingAs($user)
        ->get(route('reports.inventory', ['estado' => 'DEVUELTO']))
        ->assertOk()
        ->assertSee($esc['item']->codigo);
});

it('el export XLSX de movimientos funciona tras una revisión', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $this->actingAs($user)
        ->post(route('items.revision.store', $esc['detalleDevolucion']), ['resultado' => 'REPARACION'])
        ->assertSessionHasNoErrors();

    $this->actingAs($user)
        ->get(route('reports.movimientos.xlsx'))
        ->assertOk();
});

it('el export PDF de inventario funciona con un item devuelto', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $this->actingAs($user)
        ->get(route('reports.inventory.pdf'))
        ->assertOk();
});

/*
 * =========================
 * Español (B13)
 * =========================
 */

it('la aplicación usa español como idioma por defecto', function () {
    expect(config('app.locale'))->toBe('es')
        ->and(config('app.fallback_locale'))->toBe('es');
});

it('los mensajes de validación están en español (campo obligatorio)', function () {
    $v = Validator::make(['nombre' => ''], ['nombre' => 'required']);

    expect($v->errors()->first('nombre'))->toBe('El campo nombre es obligatorio.');
});

it('el email inválido se reporta en español', function () {
    $v = Validator::make(['email' => 'no-es-un-email'], ['email' => 'email']);

    expect($v->errors()->first('email'))->toBe('El correo electrónico debe ser una dirección válida.');
});

it('el error de login usa el texto en español', function () {
    expect(__('auth.failed'))->toBe('Estas credenciales no coinciden con nuestros registros.');
});

it('la validación del formulario de revisión se muestra en español', function () {
    $esc = integEscenario();
    $user = integRevisionista();

    $this->actingAs($user)
        ->post(route('items.revision.store', $esc['detalleDevolucion']), [])
        ->assertSessionHasErrors(['resultado' => 'El campo resultado es obligatorio.']);
});

/*
 * =========================
 * Permisos (B13)
 * =========================
 */

it('sin items.revisar_devolucion el GET de revisión responde 403', function () {
    $esc = integEscenario();
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    $this->actingAs($user)
        ->get(route('items.revision', $esc['detalleDevolucion']))
        ->assertForbidden();
});

it('sin items.revisar_devolucion el POST de revisión responde 403 sin mutar nada', function () {
    $esc = integEscenario();
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    $this->actingAs($user)
        ->post(route('items.revision.store', $esc['detalleDevolucion']), ['resultado' => 'BAJA'])
        ->assertForbidden();

    expect(RevisionDevolucion::count())->toBe(0);
    expect($esc['item']->refresh()->estado)->toBe('DEVUELTO');
});

it('el rol Admin revisa una devolución', function () {
    $this->seed(RolesAndAdminSeeder::class);
    $esc = integEscenario();

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
        ->post(route('items.revision.store', $esc['detalleDevolucion']), ['resultado' => 'DISPONIBLE'])
        ->assertSessionHasNoErrors();

    expect($esc['item']->refresh()->estado)->toBe('DISPONIBLE');
});

it('el rol Almacen revisa una devolución', function () {
    $this->seed(RolesAndAdminSeeder::class);
    $esc = integEscenario();

    $almacen = User::factory()->create();
    $almacen->assignRole('Almacen');

    $this->actingAs($almacen)
        ->post(route('items.revision.store', $esc['detalleDevolucion']), ['resultado' => 'REPARACION'])
        ->assertSessionHasNoErrors();

    expect($esc['item']->refresh()->estado)->toBe('REPARACION');
});

it('el rol Ventas recibe 403 en la revisión', function () {
    $this->seed(RolesAndAdminSeeder::class);
    $esc = integEscenario();

    $ventas = User::factory()->create();
    $ventas->assignRole('Ventas');

    $this->actingAs($ventas)
        ->get(route('items.revision', $esc['detalleDevolucion']))
        ->assertForbidden();
});

it('el rol Auditor recibe 403 en la revisión (solo lectura)', function () {
    $this->seed(RolesAndAdminSeeder::class);
    $esc = integEscenario();

    $auditor = User::factory()->create();
    $auditor->assignRole('Auditor');

    $this->actingAs($auditor)
        ->post(route('items.revision.store', $esc['detalleDevolucion']), ['resultado' => 'BAJA'])
        ->assertForbidden();

    expect(RevisionDevolucion::count())->toBe(0);
});

it('los roles legacy Operador y Consulta reciben 403 en la revisión', function () {
    $this->seed(RolesAndAdminSeeder::class);
    $esc = integEscenario();

    foreach (['Operador', 'Consulta'] as $rol) {
        $user = User::factory()->create();
        $user->assignRole($rol);

        $this->actingAs($user)
            ->get(route('items.revision', $esc['detalleDevolucion']))
            ->assertForbidden();
    }
});

it('items.revisar_devolucion se asigna a Admin y Almacen pero no a Ventas/Auditor/legacy', function () {
    $this->seed(RolesAndAdminSeeder::class);

    expect(Role::findByName('Admin', 'web')->hasPermissionTo('items.revisar_devolucion'))->toBeTrue();
    expect(Role::findByName('Almacen', 'web')->hasPermissionTo('items.revisar_devolucion'))->toBeTrue();

    foreach (['Ventas', 'Auditor', 'Operador', 'Consulta'] as $rol) {
        expect(Role::findByName($rol, 'web')->hasPermissionTo('items.revisar_devolucion'))->toBeFalse();
    }

    expect(Permission::count())->toBe(40);
});
