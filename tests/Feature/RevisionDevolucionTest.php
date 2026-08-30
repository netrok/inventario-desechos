<?php

use App\Models\DocumentoPostventaDetalle;
use App\Models\Item;
use App\Models\Movimiento;
use App\Models\RevisionDevolucion;
use App\Models\User;
use App\Models\Venta;
use App\Services\PostventaService;
use App\Services\RevisionDevolucionService;
use App\Support\Money;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([
        'items.ver',
        'items.editar',
        'items.cambiar_estado',
        'items.revisar_devolucion',
        'ventas.ver',
        'ventas.crear',
        'ventas.devolver',
        'dashboard.ver',
        'reportes.ver',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function () {
    Movimiento::flushEventListeners();
    app('events')->forget('eloquent.retrieved: '.Item::class);
});

function revisionRevisor(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo([
        'items.ver',
        'items.revisar_devolucion',
        'ventas.ver',
        'ventas.crear',
        'ventas.devolver',
        'items.cambiar_estado',
    ]);

    return $user;
}

/**
 * Venta vendida y devuelta en su totalidad: el Item queda DEVUELTO y existe
 * la devolución concreta (DocumentoPostventaDetalle) pendiente de revisión.
 */
function revisionEscenario(float $precio = 120.0): array
{
    $vendedor = User::factory()->create();
    $item = Item::create(['estado' => 'VENDIDO', 'precio' => $precio]);

    $venta = Venta::create([
        'user_id' => $vendedor->id,
        'total' => Money::aPrecio(Money::aCentavos($precio)),
        'forma_pago' => 'EFECTIVO',
    ]);

    $detalleVenta = $venta->detalles()->create(['item_id' => $item->id, 'precio' => $precio]);
    $item->update(['estado' => 'VENDIDO']);

    // La devolución es un acto transaccional del usuario autenticado.
    test()->actingAs($vendedor);

    $doc = app(PostventaService::class)->devolver(
        $venta,
        [$detalleVenta->id],
        'El cliente devolvió el equipo del taller.',
        'EFECTIVO'
    );

    $detalleDevolucion = DocumentoPostventaDetalle::query()->where('item_id', $item->id)->firstOrFail();

    return compact('item', 'venta', 'doc', 'detalleDevolucion', 'detalleVenta');
}

function revisionServicio(): RevisionDevolucionService
{
    return app(RevisionDevolucionService::class);
}

/*
 * =========================
 * Núcleo de la revisión (servicio)
 * =========================
 */

it('revisa a DISPONIBLE y reincorpora el item al inventario vendible', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    $revision = revisionServicio()->revisar($esc['detalleDevolucion']->id, 'DISPONIBLE', null, $revisor->id);

    expect($revision->resultado)->toBe('DISPONIBLE');
    expect($esc['item']->refresh()->estado)->toBe('DISPONIBLE');
    expect($revision->item_id)->toBe($esc['item']->id);
});

it('revisa a REPARACION y envía el item a taller', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    revisionServicio()->revisar($esc['detalleDevolucion']->id, 'REPARACION', 'Falla en fuente de poder.', $revisor->id);

    expect($esc['item']->refresh()->estado)->toBe('REPARACION');
});

it('revisa a BAJA y retira el item del inventario operativo', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    revisionServicio()->revisar($esc['detalleDevolucion']->id, 'BAJA', 'Placa quemada.', $revisor->id);

    expect($esc['item']->refresh()->estado)->toBe('BAJA');
});

it('registra al usuario actor como responsable de la revisión', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    $revision = revisionServicio()->revisar($esc['detalleDevolucion']->id, 'DISPONIBLE', null, $revisor->id);

    expect($revision->user_id)->toBe($revisor->id);
    expect($esc['doc']->user->exists())->toBeTrue();
});

it('guarda observaciones opcionales', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    $revision = revisionServicio()->revisar(
        $esc['detalleDevolucion']->id,
        'DISPONIBLE',
        'Revisión completa, sin daños visibles.',
        $revisor->id
    );

    expect($revision->observaciones)->toBe('Revisión completa, sin daños visibles.');
});

it('persiste todos los campos de trazabilidad (relaciones y timestamps)', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    $revision = revisionServicio()->revisar($esc['detalleDevolucion']->id, 'REPARACION', 'Pendiente de fuente.', $revisor->id);

    $persistida = RevisionDevolucion::query()->findOrFail($revision->id);
    expect($persistida->item_id)->toBe($esc['item']->id)
        ->and($persistida->documento_postventa_detalle_id)->toBe($esc['detalleDevolucion']->id)
        ->and($persistida->user_id)->toBe($revisor->id)
        ->and($persistida->resultado)->toBe('REPARACION')
        ->and($persistida->observaciones)->toBe('Pendiente de fuente.')
        ->and($persistida->created_at)->not->toBeNull()
        ->and($persistida->updated_at)->not->toBeNull();
});

it('la relación detalle->revision devuelve la revisión registrada', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    $revision = revisionServicio()->revisar($esc['detalleDevolucion']->id, 'DISPONIBLE', null, $revisor->id);

    expect($esc['detalleDevolucion']->revision->id)->toBe($revision->id);
});

it('la relación item->revisiones agrupa el historial de revisiones del item', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    $revision = revisionServicio()->revisar($esc['detalleDevolucion']->id, 'DISPONIBLE', null, $revisor->id);

    expect($esc['item']->refresh()->revisiones->count())->toBe(1);
    expect($esc['item']->revisiones->first()->id)->toBe($revision->id);
});

it('la relación item->documentosPostventaDetalle expone las devoluciones concretas', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    $revision = revisionServicio()->revisar($esc['detalleDevolucion']->id, 'DISPONIBLE', null, $revisor->id);

    expect($revision->id)->toBeInt();
    $item = $esc['item']->refresh();
    expect($item->documentosPostventaDetalle->contains('id', $esc['detalleDevolucion']->id))->toBeTrue();
});

it('rechaza un resultado inexistente sin tocar la base de datos', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    expect(fn () => revisionServicio()->revisar($esc['detalleDevolucion']->id, 'INEXISTENTE', null, $revisor->id))
        ->toThrow(DomainException::class, 'El resultado de revisión no es válido.');

    expect($esc['item']->refresh()->estado)->toBe('DEVUELTO');
    expect(RevisionDevolucion::count())->toBe(0);
});

it('genera un movimiento REVISION_DEVOLUCION con de DEVUELTO hacia el resultado', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    revisionServicio()->revisar($esc['detalleDevolucion']->id, 'DISPONIBLE', null, $revisor->id);

    $mov = Movimiento::query()->where('tipo', Movimiento::TIPO_REVISION_DEVOLUCION)->first();
    expect($mov)->not->toBeNull();
    expect($mov->item_id)->toBe($esc['item']->id);
    expect($mov->de_estado)->toBe('DEVUELTO');
    expect($mov->a_estado)->toBe('DISPONIBLE');
});

it('las notas del movimiento incluyen el folio de la devolución y el resultado', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    revisionServicio()->revisar($esc['detalleDevolucion']->id, 'REPARACION', 'Fuente dañada.', $revisor->id);

    $mov = Movimiento::query()->where('tipo', Movimiento::TIPO_REVISION_DEVOLUCION)->firstOrFail();
    expect($mov->notas)->toContain($esc['doc']->folio);
    expect($mov->notas)->toContain('REPARACION');
    expect($mov->notas)->toContain('Fuente dañada.');
});

it('el movimiento conserva al revisor como usuario actor', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    revisionServicio()->revisar($esc['detalleDevolucion']->id, 'BAJA', null, $revisor->id);

    $mov = Movimiento::query()->where('tipo', Movimiento::TIPO_REVISION_DEVOLUCION)->firstOrFail();
    expect($mov->user_id)->toBe($revisor->id);
});

it('impide revisar dos veces la misma devolución', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    revisionServicio()->revisar($esc['detalleDevolucion']->id, 'DISPONIBLE', null, $revisor->id);

    expect(fn () => revisionServicio()->revisar($esc['detalleDevolucion']->id, 'REPARACION', null, $revisor->id))
        ->toThrow(DomainException::class, 'Esta devolución ya fue revisada.');

    expect(RevisionDevolucion::count())->toBe(1);
    expect($esc['item']->refresh()->estado)->toBe('DISPONIBLE');
});

it('rechaza revisar un item que ya no está DEVUELTO', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    $esc['item']->update(['estado' => 'REPARACION']);

    expect(fn () => revisionServicio()->revisar($esc['detalleDevolucion']->id, 'DISPONIBLE', null, $revisor->id))
        ->toThrow(DomainException::class, 'El artículo no está DEVUELTO; no se puede revisar en este momento.');

    expect(RevisionDevolucion::count())->toBe(0);
});

it('la constraint UNIQUE de BD impide una segunda revisión del mismo detalle', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    revisionServicio()->revisar($esc['detalleDevolucion']->id, 'DISPONIBLE', null, $revisor->id);

    DB::table('revisiones_devolucion')
        ->insert([
            'item_id' => $esc['item']->id,
            'documento_postventa_detalle_id' => $esc['detalleDevolucion']->id,
            'user_id' => $revisor->id,
            'resultado' => 'BAJA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
})->throws(\Illuminate\Database\QueryException::class);

it('la constraint CHECK de BD rechaza un resultado inválido', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    DB::table('revisiones_devolucion')
        ->insert([
            'item_id' => $esc['item']->id,
            'documento_postventa_detalle_id' => $esc['detalleDevolucion']->id,
            'user_id' => $revisor->id,
            'resultado' => 'VENDIDO',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
})->throws(\Illuminate\Database\QueryException::class);

it('no permite eliminar un item con revisiones (FK RESTRICT)', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    revisionServicio()->revisar($esc['detalleDevolucion']->id, 'DISPONIBLE', null, $revisor->id);

    DB::table('items')->where('id', $esc['item']->id)->delete();
})->throws(\Illuminate\Database\QueryException::class);

/*
 * =========================
 * Bypass del cambio de estado genérico (B13)
 * =========================
 */

it('el endpoint genérico bloquea DEVUELTO->DISPONIBLE con el mensaje oficial', function () {
    $esc = revisionEscenario();
    $admin = revisionRevisor();

    $this->actingAs($admin)
        ->post(route('items.changeEstado', $esc['item']->id), ['estado' => 'DISPONIBLE'])
        ->assertSessionHasErrors('estado');

    expect($esc['item']->refresh()->estado)->toBe('DEVUELTO');
    expect(RevisionDevolucion::count())->toBe(0);
});

it('el endpoint genérico bloquea DEVUELTO->REPARACION', function () {
    $esc = revisionEscenario();
    $admin = revisionRevisor();

    $this->actingAs($admin)
        ->post(route('items.changeEstado', $esc['item']->id), ['estado' => 'REPARACION'])
        ->assertSessionHasErrors('estado');

    expect($esc['item']->refresh()->estado)->toBe('DEVUELTO');
});

it('el endpoint genérico bloquea DEVUELTO->BAJA', function () {
    $esc = revisionEscenario();
    $admin = revisionRevisor();

    $this->actingAs($admin)
        ->post(route('items.changeEstado', $esc['item']->id), ['estado' => 'BAJA'])
        ->assertSessionHasErrors('estado');

    expect($esc['item']->refresh()->estado)->toBe('DEVUELTO');
});

it('el formulario de edición tampoco permite salir de DEVUELTO', function () {
    $esc = revisionEscenario();
    $admin = revisionRevisor();
    $admin->givePermissionTo('items.editar');

    $categoria = App\Models\Categoria::create(['nombre' => 'Equipos']);

    $this->actingAs($admin)
        ->put(route('items.update', $esc['item']->id), [
            'codigo' => $esc['item']->codigo,
            'estado' => 'DISPONIBLE',
            'categoria_id' => $categoria->id,
            'marca' => 'Marca',
            'modelo' => 'Modelo',
        ])
        ->assertSessionHasErrors('estado');

    expect($esc['item']->refresh()->estado)->toBe('DEVUELTO');
});

it('un intento de venta en POS de un item DEVUELTO sigue rechazado', function () {
    $esc = revisionEscenario();
    $admin = revisionRevisor();

    $this->actingAs($admin)
        ->post(route('pos.add'), ['codigo' => $esc['item']->codigo])
        ->assertSessionHasErrors('codigo');
});

/*
 * =========================
 * Atomicidad y concurrencia
 * =========================
 */

it('hace rollback completo si falla el Movimiento de la revisión', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    Movimiento::creating(function () {
        throw new RuntimeException('fallo simulado en el movimiento de revisión');
    });

    $this->actingAs($revisor)
        ->post(route('items.revision.store', $esc['detalleDevolucion']), [
            'resultado' => 'DISPONIBLE',
        ])
        ->assertStatus(500);

    $this->assertDatabaseCount('revisiones_devolucion', 0);
    $this->assertDatabaseCount('movimientos', 1);

    expect(Movimiento::query()->where('tipo', Movimiento::TIPO_REVISION_DEVOLUCION)->count())->toBe(0);

    expect($esc['item']->refresh()->estado)->toBe('DEVUELTO');
});

it('la doble revisión en peticiones secuenciales se serializa: la segunda falla sin dejar filas', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    $this->actingAs($revisor)
        ->post(route('items.revision.store', $esc['detalleDevolucion']), ['resultado' => 'DISPONIBLE'])
        ->assertSessionHasNoErrors();

    $this->actingAs($revisor)
        ->post(route('items.revision.store', $esc['detalleDevolucion']), ['resultado' => 'REPARACION'])
        ->assertSessionHasErrors('resultado');

    expect(RevisionDevolucion::count())->toBe(1);
    expect($esc['item']->refresh()->estado)->toBe('DISPONIBLE');
});

it('una revisión fallida bajo lock deja el item intacto (rollback total)', function () {
    $esc = revisionEscenario();
    $revisor = revisionRevisor();

    $this->actingAs($revisor)
        ->post(route('items.revision.store', $esc['detalleDevolucion']), ['resultado' => 'BOGUS'])
        ->assertSessionHasErrors('resultado');

    expect(RevisionDevolucion::count())->toBe(0);
    expect($esc['item']->refresh()->estado)->toBe('DEVUELTO');
});
