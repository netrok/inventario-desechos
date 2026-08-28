<?php

use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([
        'items.ver',
        'items.cambiar_estado',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('BAJA conserva el Item en la base, mantiene deleted_at NULL y registra Movimiento BAJA', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.cambiar_estado', 'items.ver');

    $item = Item::create(['estado' => 'DISPONIBLE']);

    $response = $this->actingAs($user)
        ->from(route('items.show', $item))
        ->post(route('items.changeEstado', $item->id), [
            'estado' => 'BAJA',
            'notas' => 'Equipo fuera de operación',
        ]);

    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('success');
    $response->assertRedirect(route('items.show', $item));

    $this->assertDatabaseHas('items', ['id' => $item->id, 'estado' => 'BAJA']);
    expect(DB::table('items')->where('id', $item->id)->value('deleted_at'))->toBeNull();
    $this->assertDatabaseHas('movimientos', [
        'item_id' => $item->id,
        'tipo' => 'BAJA',
        'de_estado' => 'DISPONIBLE',
        'a_estado' => 'BAJA',
    ]);

    expect(Item::onlyTrashed()->count())->toBe(0);

    $this->actingAs($user)
        ->get(route('items.show', $item))
        ->assertOk()
        ->assertSee('BAJA');
});

it('VENDIDO conserva el Item en la base, mantiene deleted_at NULL y registra Movimiento VENTA', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.cambiar_estado', 'items.ver');

    $item = Item::create(['estado' => 'DISPONIBLE']);

    $response = $this->actingAs($user)
        ->from(route('items.show', $item))
        ->post(route('items.changeEstado', $item->id), [
            'estado' => 'VENDIDO',
        ]);

    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('success');
    $response->assertRedirect(route('items.show', $item));

    $this->assertDatabaseHas('items', ['id' => $item->id, 'estado' => 'VENDIDO']);
    expect(DB::table('items')->where('id', $item->id)->value('deleted_at'))->toBeNull();
    $this->assertDatabaseHas('movimientos', [
        'item_id' => $item->id,
        'tipo' => 'VENTA',
        'a_estado' => 'VENDIDO',
    ]);

    expect(Item::onlyTrashed()->count())->toBe(0);
});
