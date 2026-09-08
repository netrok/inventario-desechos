<?php

use App\Models\Item;
use App\Models\User;
use App\Support\ItemCodigo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach (['items.ver', 'ventas.ver', 'ventas.crear'] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function scannerViewer(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    return $user;
}

function seller(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(['ventas.ver', 'ventas.crear']);

    return $user;
}

function itemConCodigo(string $codigo): Item
{
    return Item::create(['codigo' => $codigo, 'estado' => 'DISPONIBLE']);
}

it('normaliza separadores del lector y encuentra ITM-000008', function () {
    $item = itemConCodigo('ITM-000008');
    $user = scannerViewer();

    $this->actingAs($user)
        ->get(route('items.scan', ['codigo' => 'ITM-000008']))
        ->assertRedirect(route('items.show', $item));
});

it('tolera el apóstrofe recto del lector: ITM\'000008', function () {
    $item = itemConCodigo('ITM-000008');
    $user = scannerViewer();

    $this->actingAs($user)
        ->get(route('items.scan', ['codigo' => "ITM'000008"]))
        ->assertRedirect(route('items.show', $item));
});

it('tolera la comilla tipográfica derecha: ITM’000008', function () {
    $item = itemConCodigo('ITM-000008');
    $user = scannerViewer();

    $this->actingAs($user)
        ->get(route('items.scan', ['codigo' => 'ITM’000008']))
        ->assertRedirect(route('items.show', $item));
});

it('tolera minúsculas: itm-000008', function () {
    $item = itemConCodigo('ITM-000008');
    $user = scannerViewer();

    $this->actingAs($user)
        ->get(route('items.scan', ['codigo' => 'itm-000008']))
        ->assertRedirect(route('items.show', $item));
});

it('tolera espacios alrededor e intermedios: " ITM \' 000008 "', function () {
    $item = itemConCodigo('ITM-000008');
    $user = scannerViewer();

    $this->actingAs($user)
        ->get(route('items.scan', ['codigo' => " ITM ' 000008 "]))
        ->assertRedirect(route('items.show', $item));
});

it('no reemplaza apóstrofes en strings arbitrarios', function () {
    expect(ItemCodigo::normalizarLectura("ABC'DEF"))->toBe("ABC'DEF");
    expect(ItemCodigo::normalizarLectura('ABC’DEF'))->toBe('ABC’DEF');
    expect(ItemCodigo::normalizarLectura('HOLA MUNDO'))->toBe('HOLA MUNDO');
});

it('POS agrega el Item al carrito con apóstrofe del lector ITM\'000008', function () {
    $item = Item::create(['codigo' => 'ITM-000008', 'estado' => 'DISPONIBLE', 'precio' => 1250.5]);
    $user = seller();

    $this->actingAs($user)
        ->post(route('pos.add'), ['codigo' => "ITM'000008"])
        ->assertRedirect(route('pos.index'))
        ->assertSessionHas('success');

    expect(session('pos.cart'))->toBe([$item->id]);
});

it('POS agrega el Item con código normal ITM-000008 (comportamiento actual)', function () {
    $item = Item::create(['codigo' => 'ITM-000008', 'estado' => 'DISPONIBLE', 'precio' => 1250.5]);
    $user = seller();

    $this->actingAs($user)
        ->post(route('pos.add'), ['codigo' => 'ITM-000008'])
        ->assertRedirect(route('pos.index'))
        ->assertSessionHas('success');

    expect(session('pos.cart'))->toBe([$item->id]);
});

it('código inexistente ITM\'999999 normaliza pero sigue dando no existe', function () {
    $user = seller();

    $this->actingAs($user)
        ->post(route('pos.add'), ['codigo' => "ITM'999999"])
        ->assertSessionHasErrors('codigo');

    expect(session('errors')->first('codigo'))->toContain('ITM-999999');
    expect(session('pos.cart'))->toBeNull();
});
