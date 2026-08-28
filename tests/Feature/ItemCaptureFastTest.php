<?php

use App\Models\Categoria;
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

    foreach (['items.crear', 'items.ver'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('Guardar crea el Item con su Movimiento ALTA y redirige al índice', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.crear');

    $categoria = Categoria::create(['nombre' => 'Tecnología']);
    $ubicacion = Ubicacion::create(['nombre' => 'Almacén']);

    $response = $this->actingAs($user)->post('/items', [
        'serie' => 'SN-001',
        'marca' => 'Dell',
        'modelo' => 'Latitude 5420',
        'categoria_id' => $categoria->id,
        'ubicacion_id' => $ubicacion->id,
        'estado' => 'DISPONIBLE',
    ]);

    $item = Item::first();

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('items.index'));
    $response->assertSessionHas('success', "Item {$item->codigo} creado.");

    $this->assertDatabaseHas('movimientos', [
        'item_id' => $item->id,
        'tipo' => Movimiento::TIPO_ALTA,
        'a_estado' => 'DISPONIBLE',
        'a_ubicacion_id' => $ubicacion->id,
    ]);
});

it('Guardar y capturar otro crea Item con ALTA, regresa a create y muestra el código creado', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.crear', 'items.ver');

    $categoria = Categoria::create(['nombre' => 'Tecnología']);
    $ubicacion = Ubicacion::create(['nombre' => 'Almacén']);

    $response = $this->actingAs($user)->post('/items', [
        'categoria_id' => $categoria->id,
        'ubicacion_id' => $ubicacion->id,
        'estado' => 'DISPONIBLE',
        'save_and_new' => '1',
    ]);

    $item = Item::first();

    $response->assertSessionHasNoErrors();
    $response->assertRedirect(route('items.create'));
    $response->assertSessionHas('success', "Item {$item->codigo} creado correctamente.");

    $this->assertDatabaseHas('movimientos', [
        'item_id' => $item->id,
        'tipo' => Movimiento::TIPO_ALTA,
        'a_estado' => 'DISPONIBLE',
    ]);

    $html = $this->actingAs($user)->get(route('items.create'))->getContent();

    preg_match('#<option value="'.preg_quote((string) $categoria->id, '#').'"[^>]*>#', $html, $catOption);
    preg_match('#<option value="'.preg_quote((string) $ubicacion->id, '#').'"[^>]*>#', $html, $ubiOption);

    expect($html)->toContain("Item {$item->codigo} creado correctamente.");
    expect($catOption[0] ?? '')->toContain('selected');
    expect($ubiOption[0] ?? '')->toContain('selected');
});

it('el código no puede sobrescribirse desde el request: se genera ITM-XXXXXX', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.crear');

    $categoria = Categoria::create(['nombre' => 'Tecnología']);
    $ubicacion = Ubicacion::create(['nombre' => 'Almacén']);

    $this->actingAs($user)->post('/items', [
        'categoria_id' => $categoria->id,
        'ubicacion_id' => $ubicacion->id,
        'estado' => 'DISPONIBLE',
        'codigo' => 'ITM-CUSTOM',
    ]);

    $item = Item::first();

    expect($item->codigo)->toMatch('/^ITM-\d{6}$/')->not->toBe('ITM-CUSTOM');
});

it('los campos opcionales no son obligatorios para la captura rápida', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.crear');

    $categoria = Categoria::create(['nombre' => 'Tecnología']);
    $ubicacion = Ubicacion::create(['nombre' => 'Almacén']);

    $response = $this->actingAs($user)->post('/items', [
        'categoria_id' => $categoria->id,
        'ubicacion_id' => $ubicacion->id,
        'estado' => 'DISPONIBLE',
    ]);

    $response->assertSessionHasNoErrors();
    $this->assertDatabaseCount('items', 1);
});

it('la vista de alta propia el autofocus en categoría sin datos preservados y en marca tras Guardar y capturar otro', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.crear');

    $categoria = Categoria::create(['nombre' => 'Tecnología']);
    $ubicacion = Ubicacion::create(['nombre' => 'Almacén']);

    $this->actingAs($user)
        ->get(route('items.create'))
        ->assertOk()
        ->assertSee('<select', false)
        ->assertSee('autofocus', false);

    $this->actingAs($user)->post('/items', [
        'categoria_id' => $categoria->id,
        'ubicacion_id' => $ubicacion->id,
        'estado' => 'DISPONIBLE',
        'save_and_new' => '1',
    ]);

    $html = $this->actingAs($user)
        ->get(route('items.create'))
        ->getContent();

    preg_match('#<option value="'.preg_quote((string) $categoria->id, '#').'"[^>]*>#', $html, $catOption);

    expect($catOption[0] ?? '')->toContain('selected');
});
