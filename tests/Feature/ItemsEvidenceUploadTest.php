<?php

use App\Models\Item;
use App\Models\Ubicacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Permission::findOrCreate('items.cambiar_estado', 'web');
    Permission::findOrCreate('items.mover', 'web');

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Storage::fake('public');
});

it('acepta una evidencia JPG en un cambio de estado', function () {
    $item = Item::create(['estado' => 'DISPONIBLE']);
    $user = User::factory()->create();
    $user->givePermissionTo('items.cambiar_estado');

    $this->actingAs($user)
        ->from(route('items.show', $item))
        ->post(route('items.changeEstado', $item->id), [
            'estado' => 'REPARACION',
            'evidencia' => UploadedFile::fake()->image('evidencia.jpg', 5, 5),
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success')
        ->assertRedirect(route('items.show', $item));
});

it('acepta una evidencia PNG en un cambio de estado', function () {
    $item = Item::create(['estado' => 'DISPONIBLE']);
    $user = User::factory()->create();
    $user->givePermissionTo('items.cambiar_estado');

    $this->actingAs($user)
        ->from(route('items.show', $item))
        ->post(route('items.changeEstado', $item->id), [
            'estado' => 'REPARACION',
            'evidencia' => UploadedFile::fake()->image('evidencia.png', 5, 5),
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success')
        ->assertRedirect(route('items.show', $item));
});

it('acepta una evidencia PDF en un movimiento de ubicacion', function () {
    $item = Item::create(['estado' => 'DISPONIBLE']);
    $ubicacion = Ubicacion::create(['nombre' => 'Taller']);
    $user = User::factory()->create();
    $user->givePermissionTo('items.mover');

    $this->actingAs($user)
        ->from(route('items.show', $item))
        ->post(route('items.moveUbicacion', $item->id), [
            'ubicacion_id' => $ubicacion->id,
            'evidencia' => UploadedFile::fake()->createWithContent(
                'evidencia.pdf',
                "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF"
            ),
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success')
        ->assertRedirect(route('items.show', $item));

    $this->assertDatabaseHas('movimientos', ['item_id' => $item->id, 'tipo' => 'TRASLADO']);
});

it('rechaza una evidencia de tipo no permitido', function () {
    $item = Item::create(['estado' => 'DISPONIBLE']);
    $user = User::factory()->create();
    $user->givePermissionTo('items.cambiar_estado');

    $this->actingAs($user)
        ->from(route('items.show', $item))
        ->post(route('items.changeEstado', $item->id), [
            'estado' => 'REPARACION',
            'evidencia' => UploadedFile::fake()->createWithContent(
                'evidencia.txt',
                'contenido de texto plano no permitido'
            ),
        ])
        ->assertSessionHasErrors('evidencia');
});
