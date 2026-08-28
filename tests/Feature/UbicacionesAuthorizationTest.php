<?php

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
