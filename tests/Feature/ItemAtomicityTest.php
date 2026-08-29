<?php

use App\Models\Categoria;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\Ubicacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([
        'items.crear',
        'items.editar',
        'items.cambiar_estado',
        'items.mover',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Storage::fake('public');
});

afterEach(function () {
    Movimiento::flushEventListeners();
    app('events')->forget('eloquent.retrieved: '.Item::class);
});

function makeMovimientoCreationFail(): void
{
    Movimiento::creating(function () {
        throw new RuntimeException('fallo simulado al crear Movimiento');
    });
}

function flipItemAfterFirstRetrieval(int $itemId, array $changes): void
{
    $flipped = false;

    Item::retrieved(function (Item $item) use ($itemId, $changes, &$flipped) {
        if ($item->id !== $itemId || $flipped) {
            return;
        }
        $flipped = true;
        DB::table('items')->where('id', $itemId)->update($changes);
    });
}

it('store hace rollback si falla el Movimiento ALTA y limpia la foto', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.crear');

    $categoria = Categoria::create(['nombre' => 'Tecnología']);
    $ubicacion = Ubicacion::create(['nombre' => 'Almacén']);

    makeMovimientoCreationFail();

    $response = $this->actingAs($user)->post('/items', [
        'serie' => 'SN-001',
        'categoria_id' => $categoria->id,
        'ubicacion_id' => $ubicacion->id,
        'estado' => 'DISPONIBLE',
        'foto' => UploadedFile::fake()->image('item.jpg', 5, 5),
    ]);

    $response->assertStatus(500);

    $this->assertDatabaseCount('items', 0);
    $this->assertDatabaseCount('movimientos', 0);
    expect(Storage::disk('public')->allFiles('items'))->toBe([]);
});

it('update hace rollback si falla el Movimiento y conserva la foto anterior', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.editar', 'items.cambiar_estado', 'items.mover');

    $categoria = Categoria::create(['nombre' => 'Tecnología']);
    $ubicacionA = Ubicacion::create(['nombre' => 'Almacén']);
    $ubicacionB = Ubicacion::create(['nombre' => 'Taller']);

    Storage::disk('public')->put('items/foto_anterior.jpg', 'contenido');

    $item = Item::create([
        'estado' => 'DISPONIBLE',
        'ubicacion_id' => $ubicacionA->id,
        'categoria_id' => $categoria->id,
        'foto_path' => 'items/foto_anterior.jpg',
    ]);

    makeMovimientoCreationFail();

    $response = $this->actingAs($user)->put(route('items.update', $item), [
        'serie' => 'SN-XX',
        'categoria_id' => $categoria->id,
        'ubicacion_id' => $ubicacionB->id,
        'estado' => 'REPARACION',
        'foto' => UploadedFile::fake()->image('nueva.jpg', 5, 5),
    ]);

    $response->assertStatus(500);

    $this->assertDatabaseHas('items', [
        'id' => $item->id,
        'estado' => 'DISPONIBLE',
        'ubicacion_id' => $ubicacionA->id,
        'foto_path' => 'items/foto_anterior.jpg',
    ]);
    $this->assertDatabaseCount('movimientos', 0);
    expect(Storage::disk('public')->allFiles('items'))->toBe(['items/foto_anterior.jpg']);
});

it('changeEstado hace rollback si falla el Movimiento y limpia la evidencia', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.cambiar_estado');

    $item = Item::create(['estado' => 'DISPONIBLE']);

    makeMovimientoCreationFail();

    $response = $this->actingAs($user)
        ->from(route('items.show', $item))
        ->post(route('items.changeEstado', $item->id), [
            'estado' => 'REPARACION',
            'evidencia' => UploadedFile::fake()->image('evidencia.jpg', 5, 5),
        ]);

    $response->assertStatus(500);

    $this->assertDatabaseHas('items', ['id' => $item->id, 'estado' => 'DISPONIBLE']);
    $this->assertDatabaseCount('movimientos', 0);
    expect(Storage::disk('public')->allFiles('movimientos'))->toBe([]);
});

it('moveUbicacion hace rollback si falla el Movimiento y limpia la evidencia', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.mover');

    $ubicacionA = Ubicacion::create(['nombre' => 'Almacén']);
    $ubicacionB = Ubicacion::create(['nombre' => 'Taller']);

    $item = Item::create(['estado' => 'DISPONIBLE', 'ubicacion_id' => $ubicacionA->id]);

    makeMovimientoCreationFail();

    $response = $this->actingAs($user)
        ->from(route('items.show', $item))
        ->post(route('items.moveUbicacion', $item->id), [
            'ubicacion_id' => $ubicacionB->id,
            'evidencia' => UploadedFile::fake()->image('evidencia.jpg', 5, 5),
        ]);

    $response->assertStatus(500);

    $this->assertDatabaseHas('items', ['id' => $item->id, 'ubicacion_id' => $ubicacionA->id]);
    $this->assertDatabaseCount('movimientos', 0);
    expect(Storage::disk('public')->allFiles('movimientos'))->toBe([]);
});

it('changeEstado sin cambios con evidencia no almacena evidencia ni crea Movimiento', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.cambiar_estado');

    $item = Item::create(['estado' => 'DISPONIBLE']);

    $response = $this->actingAs($user)
        ->from(route('items.show', $item))
        ->post(route('items.changeEstado', $item->id), [
            'estado' => 'DISPONIBLE',
            'evidencia' => UploadedFile::fake()->image('evidencia_noop.jpg', 5, 5),
        ]);

    $response->assertRedirect(route('items.show', $item));
    $response->assertSessionHas('success', 'Estado sin cambios (DISPONIBLE).');

    $this->assertDatabaseHas('items', ['id' => $item->id, 'estado' => 'DISPONIBLE']);
    $this->assertDatabaseCount('movimientos', 0);
    expect(Storage::disk('public')->allFiles('movimientos'))->toBe([]);
});

it('moveUbicacion sin cambios con evidencia no almacena evidencia ni crea Movimiento', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.mover');

    $ubicacion = Ubicacion::create(['nombre' => 'Almacén']);
    $item = Item::create(['estado' => 'DISPONIBLE', 'ubicacion_id' => $ubicacion->id]);

    $response = $this->actingAs($user)
        ->from(route('items.show', $item))
        ->post(route('items.moveUbicacion', $item->id), [
            'ubicacion_id' => $ubicacion->id,
            'evidencia' => UploadedFile::fake()->image('evidencia_noop.jpg', 5, 5),
        ]);

    $response->assertRedirect(route('items.show', $item));
    $response->assertSessionHas('success', 'Ubicación sin cambios.');

    $this->assertDatabaseHas('items', ['id' => $item->id, 'ubicacion_id' => $ubicacion->id]);
    $this->assertDatabaseCount('movimientos', 0);
    expect(Storage::disk('public')->allFiles('movimientos'))->toBe([]);
});

it('changeEstado sin cambios detectado bajo lock no almacena la evidencia enviada', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.cambiar_estado');

    $item = Item::create(['estado' => 'DISPONIBLE']);

    flipItemAfterFirstRetrieval($item->id, ['estado' => 'RESERVADO']);

    $response = $this->actingAs($user)
        ->from(route('items.show', $item))
        ->post(route('items.changeEstado', $item->id), [
            'estado' => 'RESERVADO',
            'evidencia' => UploadedFile::fake()->image('evidencia_conc.jpg', 5, 5),
        ]);

    $response->assertRedirect(route('items.show', $item));
    $response->assertSessionHas('success', 'Estado sin cambios (RESERVADO).');

    $this->assertDatabaseHas('items', ['id' => $item->id, 'estado' => 'RESERVADO']);
    $this->assertDatabaseCount('movimientos', 0);
    expect(Storage::disk('public')->allFiles('movimientos'))->toBe([]);
});

it('moveUbicacion sin cambios detectado bajo lock no almacena la evidencia enviada', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.mover');

    $ubicacionA = Ubicacion::create(['nombre' => 'Almacén']);
    $ubicacionB = Ubicacion::create(['nombre' => 'Taller']);

    $item = Item::create(['estado' => 'DISPONIBLE', 'ubicacion_id' => $ubicacionA->id]);

    flipItemAfterFirstRetrieval($item->id, ['ubicacion_id' => $ubicacionB->id]);

    $response = $this->actingAs($user)
        ->from(route('items.show', $item))
        ->post(route('items.moveUbicacion', $item->id), [
            'ubicacion_id' => $ubicacionB->id,
            'evidencia' => UploadedFile::fake()->image('evidencia_conc.jpg', 5, 5),
        ]);

    $response->assertRedirect(route('items.show', $item));
    $response->assertSessionHas('success', 'Ubicación sin cambios.');

    $this->assertDatabaseHas('items', ['id' => $item->id, 'ubicacion_id' => $ubicacionB->id]);
    $this->assertDatabaseCount('movimientos', 0);
    expect(Storage::disk('public')->allFiles('movimientos'))->toBe([]);
});

it('update con foto nueva y transición inválida detectada bajo lock hace rollback y conserva la foto anterior', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('items.editar');

    $categoria = Categoria::create(['nombre' => 'Tecnología']);
    $ubicacion = Ubicacion::create(['nombre' => 'Almacén']);

    Storage::disk('public')->put('items/foto_anterior.jpg', 'contenido');

    $item = Item::create([
        'estado' => 'DISPONIBLE',
        'ubicacion_id' => $ubicacion->id,
        'categoria_id' => $categoria->id,
        'foto_path' => 'items/foto_anterior.jpg',
    ]);

    flipItemAfterFirstRetrieval($item->id, ['estado' => 'RESERVADO']);

    $response = $this->actingAs($user)
        ->from(route('items.show', $item))
        ->put(route('items.update', $item), [
            'serie' => 'SN-XX',
            'categoria_id' => $categoria->id,
            'ubicacion_id' => $ubicacion->id,
            'estado' => 'REPARACION',
            'foto' => UploadedFile::fake()->image('nueva.jpg', 5, 5),
        ]);

    $response->assertSessionHasErrors('estado');
    $response->assertRedirect(route('items.show', $item));

    $this->assertDatabaseHas('items', [
        'id' => $item->id,
        'estado' => 'RESERVADO',
        'foto_path' => 'items/foto_anterior.jpg',
    ]);
    $this->assertDatabaseCount('movimientos', 0);
    expect(Storage::disk('public')->allFiles('items'))->toBe(['items/foto_anterior.jpg']);
});
