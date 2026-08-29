<?php

use App\Models\Categoria;
use App\Models\Item;
use App\Models\Ubicacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([
        'items.ver',
        'items.crear',
        'items.editar',
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

it('no registra rutas web de papelera ni de borrado de items', function () {
    expect(Route::has('items.trash'))->toBeFalse();
    expect(Route::has('items.restore'))->toBeFalse();
    expect(Route::has('items.forceDelete'))->toBeFalse();
    expect(Route::has('items.destroy'))->toBeFalse();
});

it('responde 404 para la antigua papelera /items-trash', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    $this->actingAs($user)
        ->get('/items-trash')
        ->assertNotFound();
});

it('responde 404 para el antiguo endpoint de restore', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    $this->actingAs($user)
        ->post('/items/999999/restore')
        ->assertNotFound();
});

it('responde 404 para el antiguo endpoint de borrado definitivo', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    $this->actingAs($user)
        ->delete('/items/999999/force')
        ->assertNotFound();
});

it('no permite DELETE web de items', function () {
    $item = Item::create(['estado' => 'DISPONIBLE']);
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    $this->actingAs($user)
        ->delete(route('items.show', $item))
        ->assertMethodNotAllowed();
});

it('no muestra botones de eliminar ni papelera en la vista de item', function () {
    $item = Item::create(['estado' => 'DISPONIBLE']);
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver', 'items.editar');

    $this->actingAs($user)
        ->get(route('items.show', $item))
        ->assertOk()
        ->assertDontSee('Eliminar')
        ->assertDontSee('Papelera')
        ->assertDontSee('Enviar este item a la papelera')
        ->assertDontSee('Ver papelera');
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

/**
 * =========================
 * Granularidad de PUT /items/{item}
 * items.editar ⇒ datos descriptivos/precio/foto
 * estado ⇒ items.cambiar_estado · ubicación ⇒ items.mover
 * =========================
 */
it('edita marca/modelo/precio con items.editar aunque no tenga los permisos de ciclo de vida', function () {
    $categoria = Categoria::create(['nombre' => 'Equipos']);
    $ubicacion = Ubicacion::create(['nombre' => 'Almacén']);
    $item = Item::create([
        'estado' => 'DISPONIBLE',
        'categoria_id' => $categoria->id,
        'ubicacion_id' => $ubicacion->id,
    ]);

    $user = User::factory()->create();
    $user->givePermissionTo('items.editar');

    $this->actingAs($user)
        ->put(route('items.update', $item), [
            'categoria_id' => $categoria->id,
            'marca' => 'MarcaNueva',
            'modelo' => 'ModeloNuevo',
            'precio' => '125.50',
        ])
        ->assertRedirect(route('items.show', $item))
        ->assertSessionHasNoErrors();

    $item->refresh();
    expect($item->marca)->toBe('MarcaNueva');
    expect($item->modelo)->toBe('ModeloNuevo');
    expect((string) $item->precio)->toBe('125.50');
    expect($item->estado)->toBe('DISPONIBLE');
});

it('rechaza cambiar el estado vía update sin items.cambiar_estado', function () {
    $categoria = Categoria::create(['nombre' => 'Equipos']);
    $ubicacion = Ubicacion::create(['nombre' => 'Almacén']);
    $item = Item::create([
        'estado' => 'DISPONIBLE',
        'categoria_id' => $categoria->id,
        'ubicacion_id' => $ubicacion->id,
    ]);

    $user = User::factory()->create();
    $user->givePermissionTo('items.editar');

    $this->actingAs($user)
        ->put(route('items.update', $item), [
            'categoria_id' => $categoria->id,
            'estado' => 'REPARACION',
        ])
        ->assertSessionHasErrors('estado');

    expect($item->refresh()->estado)->toBe('DISPONIBLE');
    $this->assertDatabaseMissing('movimientos', ['item_id' => $item->id]);
});

it('rechaza mover la ubicación vía update sin items.mover', function () {
    $categoria = Categoria::create(['nombre' => 'Equipos']);
    $almacen = Ubicacion::create(['nombre' => 'Almacén']);
    $otra = Ubicacion::create(['nombre' => 'Bodega B']);
    $item = Item::create([
        'estado' => 'DISPONIBLE',
        'categoria_id' => $categoria->id,
        'ubicacion_id' => $almacen->id,
    ]);

    $user = User::factory()->create();
    $user->givePermissionTo('items.editar');

    $this->actingAs($user)
        ->put(route('items.update', $item), [
            'categoria_id' => $categoria->id,
            'ubicacion_id' => $otra->id,
        ])
        ->assertSessionHasErrors('ubicacion_id');

    expect($item->refresh()->ubicacion_id)->toBe($almacen->id);
    $this->assertDatabaseMissing('movimientos', ['item_id' => $item->id]);
});

it('permite cambiar estado y mover vía update con los permisos específicos', function () {
    $categoria = Categoria::create(['nombre' => 'Equipos']);
    $almacen = Ubicacion::create(['nombre' => 'Almacén']);
    $otra = Ubicacion::create(['nombre' => 'Bodega B']);
    $item = Item::create([
        'estado' => 'DISPONIBLE',
        'categoria_id' => $categoria->id,
        'ubicacion_id' => $almacen->id,
    ]);

    $user = User::factory()->create();
    $user->givePermissionTo('items.editar', 'items.cambiar_estado', 'items.mover');

    $this->actingAs($user)
        ->put(route('items.update', $item), [
            'categoria_id' => $categoria->id,
            'estado' => 'REPARACION',
            'ubicacion_id' => $otra->id,
        ])
        ->assertRedirect(route('items.show', $item))
        ->assertSessionHasNoErrors();

    $item->refresh();
    expect($item->estado)->toBe('REPARACION');
    expect($item->ubicacion_id)->toBe($otra->id);

    // Un solo Movimiento (AJUSTE) registra ambos cambios; no se duplican.
    $this->assertDatabaseHas('movimientos', [
        'item_id' => $item->id,
        'tipo' => 'AJUSTE',
        'de_estado' => 'DISPONIBLE',
        'a_estado' => 'REPARACION',
        'de_ubicacion_id' => $almacen->id,
        'a_ubicacion_id' => $otra->id,
    ]);
    expect(\App\Models\Movimiento::where('item_id', $item->id)->count())->toBe(1);
});
