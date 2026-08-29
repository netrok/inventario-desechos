<?php

use App\Models\Item;
use App\Models\Movimiento;
use App\Models\Ubicacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([
        'ubicaciones.ver',
        'ubicaciones.crear',
        'ubicaciones.editar',
        'ubicaciones.eliminar',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('usuario con solo ubicaciones.ver consulta el index', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('ubicaciones.ver');

    $this->actingAs($user)
        ->get('/ubicaciones')
        ->assertOk();
});

it('usuario con solo ubicaciones.ver no puede crear', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('ubicaciones.ver');

    $this->actingAs($user)
        ->get('/ubicaciones/create')
        ->assertForbidden();

    $this->actingAs($user)
        ->post('/ubicaciones', ['nombre' => 'Nueva'])
        ->assertForbidden();
});

it('usuario con solo ubicaciones.ver no puede editar', function () {
    $ubicacion = Ubicacion::create(['nombre' => 'Existente']);
    $user = User::factory()->create();
    $user->givePermissionTo('ubicaciones.ver');

    $this->actingAs($user)
        ->get(route('ubicaciones.edit', $ubicacion))
        ->assertForbidden();

    $this->actingAs($user)
        ->put(route('ubicaciones.update', $ubicacion), ['nombre' => 'Cambiada'])
        ->assertForbidden();
});

it('usuario con solo ubicaciones.ver no puede eliminar', function () {
    $ubicacion = Ubicacion::create(['nombre' => 'Existente']);
    $user = User::factory()->create();
    $user->givePermissionTo('ubicaciones.ver');

    $this->actingAs($user)
        ->delete(route('ubicaciones.destroy', $ubicacion))
        ->assertForbidden();
});

it('usuario con ubicaciones.crear puede crear', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('ubicaciones.crear');

    $this->actingAs($user)
        ->post('/ubicaciones', ['nombre' => 'Almacén Norte'])
        ->assertRedirect(route('ubicaciones.index'));

    $this->assertDatabaseHas('ubicaciones', ['nombre' => 'Almacén Norte']);
});

it('usuario con ubicaciones.editar puede editar', function () {
    $ubicacion = Ubicacion::create(['nombre' => 'Existente']);
    $user = User::factory()->create();
    $user->givePermissionTo('ubicaciones.editar');

    $this->actingAs($user)
        ->put(route('ubicaciones.update', $ubicacion), ['nombre' => 'Cambiada'])
        ->assertRedirect(route('ubicaciones.index'));

    $this->assertDatabaseHas('ubicaciones', ['nombre' => 'Cambiada']);
});

it('usuario con ubicaciones.eliminar puede eliminar', function () {
    $ubicacion = Ubicacion::create(['nombre' => 'Existente']);
    $user = User::factory()->create();
    $user->givePermissionTo('ubicaciones.eliminar');

    $this->actingAs($user)
        ->delete(route('ubicaciones.destroy', $ubicacion))
        ->assertRedirect(route('ubicaciones.index'));

    $this->assertDatabaseMissing('ubicaciones', ['id' => $ubicacion->id]);
});

it('no permite eliminar una ubicacion referenciada por movimientos', function () {
    $ubicacion = Ubicacion::create(['nombre' => 'Histórica']);
    $item = Item::create(['estado' => 'DISPONIBLE']);

    $movimiento = Movimiento::create([
        'item_id' => $item->id,
        'tipo' => 'ALTA',
        'a_estado' => 'DISPONIBLE',
        'a_ubicacion_id' => $ubicacion->id,
    ]);

    $user = User::factory()->create();
    $user->givePermissionTo('ubicaciones.eliminar');

    $this->actingAs($user)
        ->delete(route('ubicaciones.destroy', $ubicacion))
        ->assertSessionHas('error', 'No puedes eliminar esta ubicación porque es referencia de movimientos históricos.');

    $this->assertDatabaseHas('ubicaciones', ['id' => $ubicacion->id]);
    $this->assertDatabaseHas('movimientos', ['id' => $movimiento->id, 'a_ubicacion_id' => $ubicacion->id]);
});

it('no permite eliminar una ubicacion con items soft-deleted legacy', function () {
    $ubicacion = Ubicacion::create(['nombre' => 'Legacy']);
    $item = Item::create(['estado' => 'DISPONIBLE', 'ubicacion_id' => $ubicacion->id]);
    $item->delete();

    $user = User::factory()->create();
    $user->givePermissionTo('ubicaciones.eliminar');

    $this->actingAs($user)
        ->delete(route('ubicaciones.destroy', $ubicacion))
        ->assertSessionHas('error', 'No puedes eliminar esta ubicación porque tiene items asignados (incluidos históricos).');

    $this->assertDatabaseHas('ubicaciones', ['id' => $ubicacion->id]);
});
