<?php

use App\Models\Categoria;
use App\Models\Item;
use App\Models\Ubicacion;
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
        'items.crear',
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

it('rechaza VENDIDO por cambio manual de estado (VENDIDO solo se origina desde el POS)', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.cambiar_estado', 'items.ver');

    $item = Item::create(['estado' => 'DISPONIBLE']);

    $response = $this->actingAs($user)
        ->from(route('items.show', $item))
        ->post(route('items.changeEstado', $item->id), [
            'estado' => 'VENDIDO',
        ]);

    $response->assertSessionHasErrors('estado');

    $this->assertDatabaseHas('items', ['id' => $item->id, 'estado' => 'DISPONIBLE']);
    $this->assertDatabaseMissing('movimientos', ['item_id' => $item->id, 'tipo' => 'VENTA']);
    $this->assertDatabaseCount('ventas', 0);
});

it('no permite dar de alta un Item directamente como VENDIDO', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.crear');

    $categoria = Categoria::create(['nombre' => 'Equipos']);
    $ubicacion = Ubicacion::create(['nombre' => 'Almacén']);

    $response = $this->actingAs($user)
        ->post(route('items.store'), [
            'categoria_id' => $categoria->id,
            'ubicacion_id' => $ubicacion->id,
            'estado' => 'VENDIDO',
            'marca' => 'Test',
        ]);

    $response->assertSessionHasErrors('estado');

    $this->assertDatabaseCount('items', 0);
    $this->assertDatabaseCount('movimientos', 0);
    $this->assertDatabaseCount('ventas', 0);
});
