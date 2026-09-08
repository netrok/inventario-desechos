<?php

use App\Models\Caja;
use App\Models\SesionCaja;
use App\Models\User;
use App\Services\CajaService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    foreach ([
        'cajas.ver', 'cajas.configurar', 'cajas.abrir', 'cajas.operar',
        'cajas.movimientos', 'cajas.cerrar', 'cajas.ver_todas', 'cajas.ajustar',
        'cajas.entrada', 'cajas.retiro', 'dashboard.ver', 'ventas.crear', 'ventas.ver',
    ] as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function operadorCaja(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo([
        'cajas.ver', 'cajas.abrir', 'cajas.operar', 'cajas.movimientos', 'cajas.cerrar', 'dashboard.ver',
    ]);

    return $user;
}

function adminCajas(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo([
        'cajas.ver', 'cajas.configurar', 'cajas.abrir', 'cajas.operar',
        'cajas.movimientos', 'cajas.cerrar', 'cajas.ver_todas', 'cajas.ajustar',
        'cajas.entrada', 'cajas.retiro',
    ]);

    return $user;
}

function cajaAsignada(bool $activa = true, ?User $asignado = null): Caja
{
    return Caja::create([
        'nombre' => 'Caja '.uniqid('c', false),
        'activa' => $activa,
        'usuario_asignado_id' => $asignado?->id,
    ]);
}

/**
 * =================
 * ASIGNACIÓN DE OPERADOR A CAJA (B14.3.1)
 * =================
 */
it('Caja1 asignada a User1 puede abrirla', function () {
    $user1 = operadorCaja();
    $caja1 = cajaAsignada(true, $user1);

    $sesion = app(CajaService::class)->abrirSesion($caja1, $user1, 0);

    expect($sesion->estado)->toBe(SesionCaja::ESTADO_ABIERTA);
    expect($sesion->caja_id)->toBe($caja1->id);
    expect($sesion->user_id_apertura)->toBe($user1->id);
});

it('User1 NO puede abrir Caja2 (no asignada a su usuario)', function () {
    $user1 = operadorCaja();
    $user2 = operadorCaja();
    $caja2 = cajaAsignada(true, $user2);

    expect(fn () => app(CajaService::class)->abrirSesion($caja2, $user1, 0))
        ->toThrow(DomainException::class, 'no está asignada a tu usuario');

    expect(SesionCaja::count())->toBe(0);
});

it('la manipulación del caja_id del POST no permite abrir Caja2', function () {
    $user1 = operadorCaja();
    $user2 = operadorCaja();
    $caja1 = cajaAsignada(true, $user1);
    $caja2 = cajaAsignada(true, $user2);

    // Aunque el navegador envíe caja_id de Caja2, el servidor resuelve
    // server-side por asignación; NO se confía en el caja_id del request.
    $this->actingAs($user1)
        ->post(route('cajas.abrir.store'), [
            'caja_id' => $caja2->id,
            'fondo_inicial' => '0.00',
        ])
        ->assertSessionHasNoErrors();

    $sesion = SesionCaja::first();
    expect($sesion)->not->toBeNull();
    expect($sesion->caja_id)->toBe($caja1->id);
    expect($sesion->user_id_apertura)->toBe($user1->id);
});

it('un usuario sin caja asignada no puede abrir', function () {
    $user = operadorCaja();

    $this->actingAs($user)
        ->get(route('cajas.abrir'))
        ->assertOk()
        ->assertSee('No tienes una caja activa asignada')
        ->assertSee('Solicita al administrador que te asigne una.');

    $this->actingAs($user)
        ->post(route('cajas.abrir.store'), ['fondo_inicial' => '0.00'])
        ->assertSessionHasErrors('fondo_inicial');

    expect(SesionCaja::count())->toBe(0);
});

it('una caja inactiva asignada no puede abrirse', function () {
    $user = operadorCaja();
    $caja = cajaAsignada(false, $user);

    expect(fn () => app(CajaService::class)->abrirSesion($caja, $user, 0))
        ->toThrow(DomainException::class, 'inactiva');

    expect(SesionCaja::count())->toBe(0);
});

it('un usuario no puede estar asignado simultáneamente a dos cajas', function () {
    $user = operadorCaja();
    cajaAsignada(true, $user);

    // La UNIQUE de BD es la barrera de seguridad.
    expect(fn () => cajaAsignada(true, $user))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('una caja activa exige operador asignado', function () {
    $admin = adminCajas();

    $this->actingAs($admin)
        ->post(route('cajas.gestion.store'), [
            'nombre' => 'Caja sin operador',
            'activa' => '1',
        ])
        ->assertSessionHasErrors('usuario_asignado_id');

    expect(Caja::count())->toBe(0);
});

it('una caja inactiva puede quedar sin operador asignado', function () {
    $admin = adminCajas();

    $this->actingAs($admin)
        ->post(route('cajas.gestion.store'), [
            'nombre' => 'Caja inactiva sin operador',
            'activa' => '0',
            'usuario_asignado_id' => '',
        ])
        ->assertSessionHasNoErrors();

    $caja = Caja::first();
    expect($caja->activa)->toBeFalse();
    expect($caja->usuario_asignado_id)->toBeNull();
});

it('rechaza asignar a un usuario sin permiso de caja', function () {
    $admin = adminCajas();
    $sinPermiso = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('cajas.gestion.store'), [
            'nombre' => 'Caja con operador inválido',
            'usuario_asignado_id' => $sinPermiso->id,
            'activa' => '1',
        ])
        ->assertSessionHasErrors('usuario_asignado_id');

    expect(Caja::count())->toBe(0);
});

it('no se puede reasignar el operador con una sesión abierta', function () {
    $admin = adminCajas();
    $user1 = operadorCaja();
    $user2 = operadorCaja();
    $caja = cajaAsignada(true, $user1);

    $sesion = app(CajaService::class)->abrirSesion($caja, $user1, 0);

    $this->actingAs($admin)
        ->put(route('cajas.gestion.update', $caja), [
            'nombre' => $caja->nombre,
            'activa' => '1',
            'usuario_asignado_id' => $user2->id,
        ])
        ->assertSessionHasErrors('usuario_asignado_id');

    // El operador no cambia y la sesión sigue abierta.
    expect($caja->fresh()->usuario_asignado_id)->toBe($user1->id);
    expect($sesion->fresh()->estado)->toBe(SesionCaja::ESTADO_ABIERTA);
    expect($sesion->fresh()->user_id_apertura)->toBe($user1->id);
});

it('después de cerrar sí se puede reasignar el operador', function () {
    $admin = adminCajas();
    $user1 = operadorCaja();
    $user2 = operadorCaja();
    $caja = cajaAsignada(true, $user1);

    $sesion = app(CajaService::class)->abrirSesion($caja, $user1, 10000);
    app(CajaService::class)->cerrarSesion($sesion, $user1, ['100' => 1], 10000, null);

    $this->actingAs($admin)
        ->put(route('cajas.gestion.update', $caja), [
            'nombre' => $caja->nombre,
            'activa' => '1',
            'usuario_asignado_id' => $user2->id,
        ])
        ->assertSessionHasNoErrors();

    expect($caja->fresh()->usuario_asignado_id)->toBe($user2->id);

    // El nuevo operador puede abrirla.
    $nueva = app(CajaService::class)->abrirSesion($caja->fresh(), $user2, 0);
    expect($nueva->user_id_apertura)->toBe($user2->id);
});

it('la reasignación NO modifica el user_id_apertura histórico', function () {
    $admin = adminCajas();
    $user1 = operadorCaja();
    $user2 = operadorCaja();
    $caja = cajaAsignada(true, $user1);

    $sesion = app(CajaService::class)->abrirSesion($caja, $user1, 10000);
    $sesionId = $sesion->id;
    app(CajaService::class)->cerrarSesion($sesion, $user1, ['100' => 1], 10000, null);

    $this->actingAs($admin)
        ->put(route('cajas.gestion.update', $caja), [
            'nombre' => $caja->nombre,
            'activa' => '1',
            'usuario_asignado_id' => $user2->id,
        ])
        ->assertSessionHasNoErrors();

    // El histórico de apertura sigue apuntando a User1.
    expect(SesionCaja::find($sesionId)->user_id_apertura)->toBe($user1->id);
});

it('la FK RESTRICT impide borrar un usuario todavía asignado', function () {
    $user = operadorCaja();
    cajaAsignada(true, $user);

    expect(fn () => DB::statement('DELETE FROM users WHERE id = ?', [$user->id]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('una caja sigue sin poder tener dos sesiones abiertas', function () {
    $user = operadorCaja();
    $caja = cajaAsignada(true, $user);

    app(CajaService::class)->abrirSesion($caja, $user, 0);

    expect(fn () => app(CajaService::class)->abrirSesion($caja, $user, 0))
        ->toThrow(DomainException::class, 'ya tiene una sesión abierta');
});

it('un usuario sigue sin poder tener dos sesiones abiertas', function () {
    $user = operadorCaja();
    $otro = operadorCaja();
    $cajaPropia = cajaAsignada(true, $user);
    $cajaAjena = cajaAsignada(true, $otro);

    app(CajaService::class)->abrirSesion($cajaPropia, $user, 0);

    expect(fn () => app(CajaService::class)->abrirSesion($cajaAjena, $user, 0))
        ->toThrow(DomainException::class);

    expect(SesionCaja::abiertas()->count())->toBe(1);
});

it('los permisos de apertura existentes continúan funcionando', function () {
    $user = operadorCaja();
    $caja = cajaAsignada(true, $user);

    $this->actingAs($user)->get(route('cajas.abrir'))->assertOk();

    $user->revokePermissionTo('cajas.abrir');
    $this->actingAs($user)->get(route('cajas.abrir'))->assertForbidden();
});

it('el corte ciego permanece intacto tras la asignación de operador', function () {
    $user = operadorCaja();
    $caja = cajaAsignada(true, $user);
    $open = app(CajaService::class)->abrirSesion($caja, $user, Money::aCentavos('1000.00'));

    $this->actingAs($user)
        ->get(route('cajas.movimientos', $open))
        ->assertOk()
        ->assertDontSee('Efectivo esperado');

    // Corte ciego: no se filtra el esperado en sesión abierta.
    expect($open->estaAbierta())->toBeTrue();
});

/**
 * =================
 * B14.3.1 FIX 2 — Validación autoritativa transaccional
 * =================
 */
it('FIX2 no crea caja con un operador ya asignado a otra caja', function () {
    $admin = adminCajas();
    $user = operadorCaja();
    cajaAsignada(true, $user);

    $this->actingAs($admin)
        ->post(route('cajas.gestion.store'), [
            'nombre' => 'Caja en conflicto',
            'activa' => '1',
            'usuario_asignado_id' => $user->id,
        ])
        ->assertSessionHasErrors('usuario_asignado_id');

    expect(Caja::count())->toBe(1);
});

it('FIX2 update rechaza un operador ya asignado a OTRA caja', function () {
    $admin = adminCajas();
    $user1 = operadorCaja();
    $user2 = operadorCaja();
    $caja = cajaAsignada(true, $user1);
    cajaAsignada(true, $user2); // User2 ya está asignado a otra caja

    $this->actingAs($admin)
        ->put(route('cajas.gestion.update', $caja), [
            'nombre' => $caja->nombre,
            'activa' => '1',
            'usuario_asignado_id' => $user2->id,
        ])
        ->assertSessionHasErrors('usuario_asignado_id');

    expect($caja->fresh()->usuario_asignado_id)->toBe($user1->id);
});

it('FIX2 mantener el mismo operador en una caja no se trata como conflicto', function () {
    $admin = adminCajas();
    $user = operadorCaja();
    $caja = cajaAsignada(true, $user);

    $this->actingAs($admin)
        ->put(route('cajas.gestion.update', $caja), [
            'nombre' => 'Renombrada',
            'activa' => '1',
            'usuario_asignado_id' => $user->id,
        ])
        ->assertSessionHasNoErrors();

    expect($caja->fresh()->usuario_asignado_id)->toBe($user->id);
});

/**
 * =================
 * B14.3.1 FIX 3 — CHECK cajas_activa_requiere_operador (PostgreSQL)
 * =================
 */
it('FIX3 existe el CHECK cajas_activa_requiere_operador NOT VALID y lo aplica', function () {
    expect(DB::getDriverName())->toBe('pgsql');

    $row = DB::selectOne("SELECT conname, contype, convalidated FROM pg_constraint WHERE conname = 'cajas_activa_requiere_operador'");
    expect($row)->not->toBeNull();
    expect($row->contype)->toBe('c');
    expect((bool) $row->convalidated)->toBeFalse();

    // NOT VALID se sigue aplicando a filas NUEVAS: una caja activa sin
    // operador viola el CHECK a nivel de BD.
    expect(fn () => DB::table('cajas')->insert([
        'nombre' => 'Caja activa sin operador',
        'codigo' => 'CAJ-FIX3-ACTIVA',
        'activa' => true,
        'usuario_asignado_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('FIX3 una caja INACTIVA sin operador sigue siendo válida', function () {
    expect(DB::getDriverName())->toBe('pgsql');

    DB::table('cajas')->insert([
        'nombre' => 'Caja inactiva sin operador',
        'codigo' => 'CAJ-FIX3-INACTIVA',
        'activa' => false,
        'usuario_asignado_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    expect(Caja::where('nombre', 'Caja inactiva sin operador')->exists())->toBeTrue();
});

it('FIX3 coexisten la UNIQUE y la FK de usuario_asignado_id', function () {
    expect(DB::getDriverName())->toBe('pgsql');

    $rows = DB::select("SELECT conname, contype FROM pg_constraint WHERE conrelid = 'cajas'::regclass");
    $map = collect($rows)->keyBy('conname');

    expect($map->has('cajas_activa_requiere_operador'))->toBeTrue();
    expect($map->get('cajas_usuario_asignado_id_unique')->contype)->toBe('u');
    expect($map->get('cajas_usuario_asignado_id_foreign')->contype)->toBe('f');
});
