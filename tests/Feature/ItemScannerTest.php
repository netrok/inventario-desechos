<?php

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['items.ver'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('bloquea la pantalla de escaneo a un usuario sin items.ver', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('items.scan'))
        ->assertForbidden();
});

it('permite abrir la pantalla de escaneo con items.ver', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    $this->actingAs($user)
        ->get(route('items.scan'))
        ->assertOk()
        ->assertSee('Escanear / buscar equipo');
});

it('resuelve un código existente exactamente por Item.codigo', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    $item = Item::create(['codigo' => 'ITM-000123', 'estado' => 'DISPONIBLE']);

    $this->actingAs($user)
        ->get(route('items.scan', ['codigo' => 'ITM-000123']))
        ->assertRedirect(route('items.show', $item));
});

it('normaliza espacios y minúsculas en el código escaneado', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    $item = Item::create(['codigo' => 'ITM-000124', 'estado' => 'DISPONIBLE']);

    $this->actingAs($user)
        ->get(route('items.scan', ['codigo' => '  itm-000124  ']))
        ->assertRedirect(route('items.show', $item));
});

it('código inexistente muestra error, mantiene la pantalla y no crea Item', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    $this->actingAs($user)
        ->get(route('items.scan', ['codigo' => 'ITM-999999']))
        ->assertOk()
        ->assertSee('No existe un equipo con el código ITM-999999.');

    $this->assertDatabaseCount('items', 0);
});

it('encuentra un Item en estado BAJA al escanearlo', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    $item = Item::create(['codigo' => 'ITM-000456', 'estado' => 'BAJA']);

    $this->actingAs($user)
        ->get(route('items.scan', ['codigo' => 'ITM-000456']))
        ->assertRedirect(route('items.show', $item));
});

it('encuentra un Item en estado VENDIDO al escanearlo', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    $item = Item::create(['codigo' => 'ITM-000789', 'estado' => 'VENDIDO']);

    $this->actingAs($user)
        ->get(route('items.scan', ['codigo' => 'ITM-000789']))
        ->assertRedirect(route('items.show', $item));
});

it('rechaza códigos excesivamente largos sin crear Item', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    $this->actingAs($user)
        ->get(route('items.scan', ['codigo' => str_repeat('X', 41)]))
        ->assertOk()
        ->assertSee('El código es demasiado largo.');

    $this->assertDatabaseCount('items', 0);
});
