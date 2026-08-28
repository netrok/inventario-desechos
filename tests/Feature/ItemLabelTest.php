<?php

use App\Models\Categoria;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
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

it('bloquea la etiqueta a un usuario sin items.ver', function () {
    $user = User::factory()->create();
    $item = Item::create(['codigo' => 'ITM-000123', 'estado' => 'DISPONIBLE']);

    $this->actingAs($user)
        ->get(route('items.label', $item))
        ->assertForbidden();
});

it('permite ver la etiqueta con items.ver y contiene la identificación del equipo', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    $categoria = Categoria::create(['nombre' => 'Tecnología']);

    $item = Item::create([
        'codigo' => 'ITM-000123',
        'serie' => 'SN-ABC',
        'marca' => 'Dell',
        'modelo' => 'Latitude 5420',
        'categoria_id' => $categoria->id,
        'estado' => 'DISPONIBLE',
    ]);

    $this->actingAs($user)
        ->get(route('items.label', $item))
        ->assertOk()
        ->assertSee($item->codigo)
        ->assertSee($item->marca)
        ->assertSee($item->modelo)
        ->assertSee($item->serie)
        ->assertSee($categoria->nombre);
});

it('el QR codifica exactamente Item.codigo, no el id ni una URL', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    $item = Item::create(['codigo' => 'ITM-000123', 'estado' => 'DISPONIBLE']);

    $expectedQr = (string) QrCode::size(90)->generate($item->codigo);
    $idQr = (string) QrCode::size(90)->generate((string) $item->id);
    $urlQr = (string) QrCode::size(90)->generate(route('items.show', $item));

    $this->actingAs($user)
        ->get(route('items.label', $item))
        ->assertOk()
        ->assertSee($expectedQr, false)
        ->assertDontSee($idQr, false)
        ->assertDontSee($urlQr, false);
});

it('la etiqueta no imprime la foto del equipo ni campos sensibles', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.ver');

    $item = Item::create(['codigo' => 'ITM-000123', 'estado' => 'DISPONIBLE']);

    $this->actingAs($user)
        ->get(route('items.label', $item))
        ->assertOk()
        ->assertDontSee('foto_path')
        ->assertDontSee('img src')
        ->assertDontSee('csrf-token');
});
