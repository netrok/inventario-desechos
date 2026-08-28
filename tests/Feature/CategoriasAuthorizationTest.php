<?php

use App\Models\Categoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([
        'categorias.ver',
        'categorias.crear',
        'categorias.editar',
        'categorias.eliminar',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('usuario con solo categorias.ver consulta el index', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('categorias.ver');

    $this->actingAs($user)
        ->get('/categorias')
        ->assertOk();
});

it('usuario con solo categorias.ver no puede crear', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('categorias.ver');

    $this->actingAs($user)
        ->get('/categorias/create')
        ->assertForbidden();

    $this->actingAs($user)
        ->post('/categorias', ['nombre' => 'Nueva'])
        ->assertForbidden();
});

it('usuario con solo categorias.ver no puede editar', function () {
    $categoria = Categoria::create(['nombre' => 'Existente']);
    $user = User::factory()->create();
    $user->givePermissionTo('categorias.ver');

    $this->actingAs($user)
        ->get(route('categorias.edit', $categoria))
        ->assertForbidden();

    $this->actingAs($user)
        ->put(route('categorias.update', $categoria), ['nombre' => 'Cambiada'])
        ->assertForbidden();
});

it('usuario con solo categorias.ver no puede eliminar', function () {
    $categoria = Categoria::create(['nombre' => 'Existente']);
    $user = User::factory()->create();
    $user->givePermissionTo('categorias.ver');

    $this->actingAs($user)
        ->delete(route('categorias.destroy', $categoria))
        ->assertForbidden();
});

it('usuario con categorias.crear puede crear', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('categorias.crear');

    $this->actingAs($user)
        ->post('/categorias', ['nombre' => 'Nueva'])
        ->assertRedirect(route('categorias.index'));

    $this->assertDatabaseHas('categorias', ['nombre' => 'Nueva']);
});

it('usuario con categorias.editar puede editar', function () {
    $categoria = Categoria::create(['nombre' => 'Existente']);
    $user = User::factory()->create();
    $user->givePermissionTo('categorias.editar');

    $this->actingAs($user)
        ->put(route('categorias.update', $categoria), ['nombre' => 'Cambiada'])
        ->assertRedirect(route('categorias.index'));

    $this->assertDatabaseHas('categorias', ['nombre' => 'Cambiada']);
});

it('usuario con categorias.eliminar puede eliminar', function () {
    $categoria = Categoria::create(['nombre' => 'Existente']);
    $user = User::factory()->create();
    $user->givePermissionTo('categorias.eliminar');

    $this->actingAs($user)
        ->delete(route('categorias.destroy', $categoria))
        ->assertRedirect(route('categorias.index'));

    $this->assertDatabaseMissing('categorias', ['id' => $categoria->id]);
});
