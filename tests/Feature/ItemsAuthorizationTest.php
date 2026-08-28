<?php

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([
        'items.ver',
        'items.crear',
        'items.editar',
        'items.eliminar',
        'items.papelera',
        'items.restaurar',
        'items.borrar_definitivo',
        'items.cambiar_estado',
        'items.mover',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('bloquea el index de items a un usuario sin items.ver', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/items')
        ->assertForbidden();
});

it('permite el index de items a un usuario con items.ver', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    $this->actingAs($user)
        ->get('/items')
        ->assertOk();
});

it('bloquea ver un item a un usuario sin items.ver', function () {
    $item = Item::create(['estado' => Item::ESTADOS[0]]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('items.show', $item))
        ->assertForbidden();
});

it('bloquea editar a un usuario sin items.editar', function () {
    $item = Item::create(['estado' => Item::ESTADOS[0]]);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('items.edit', $item))
        ->assertForbidden();
});

it('bloquea la papelera a un usuario sin items.papelera', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/items-trash')
        ->assertForbidden();
});

it('permite ver la papelera con items.papelera sin items.eliminar', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.papelera');

    $this->actingAs($user)
        ->get('/items-trash')
        ->assertOk();
});

it('exige items.restaurar para restaurar', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/items/999999/restore')
        ->assertForbidden();
});

it('exige items.borrar_definitivo para el borrado definitivo', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete('/items/999999/force')
        ->assertForbidden();
});

it('permite cambiar estado con items.cambiar_estado sin items.editar', function () {
    $item = Item::create(['estado' => 'DISPONIBLE']);
    $user = User::factory()->create();
    $user->givePermissionTo('items.cambiar_estado');

    $this->actingAs($user)
        ->from(route('items.show', $item))
        ->post(route('items.changeEstado', $item->id), [
            'estado' => 'REPARACION',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success')
        ->assertRedirect(route('items.show', $item));

    expect($item->refresh()->estado)->toBe('REPARACION');
});

it('con items.cambiar_estado y item inexistente pasa autorizacion y responde 404', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.cambiar_estado');

    $this->actingAs($user)
        ->post('/items/999999/estado', ['estado' => 'DISPONIBLE'])
        ->assertNotFound();
});

it('permite mover con items.mover sin items.editar', function () {
    $item = Item::create(['estado' => 'DISPONIBLE']);
    $user = User::factory()->create();
    $user->givePermissionTo('items.mover');

    $this->actingAs($user)
        ->from(route('items.show', $item))
        ->post(route('items.moveUbicacion', $item->id), [
            'ubicacion_id' => '',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success')
        ->assertRedirect(route('items.show', $item));
});

it('bloquea el cambio de estado a un usuario sin permiso', function () {
    $item = Item::create(['estado' => 'DISPONIBLE']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('items.changeEstado', $item->id), ['estado' => 'BAJA'])
        ->assertForbidden();
});

it('bloquea mover a un usuario sin items.mover', function () {
    $item = Item::create(['estado' => 'DISPONIBLE']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('items.moveUbicacion', $item->id), ['ubicacion_id' => ''])
        ->assertForbidden();
});
