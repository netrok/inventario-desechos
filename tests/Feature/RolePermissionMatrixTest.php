<?php

use App\Models\Categoria;
use App\Models\Item;
use App\Models\Ubicacion;
use App\Models\User;
use App\Support\ConfiguracionAcceso;
use Database\Seeders\RolesAndAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolesAndAdminSeeder::class);
});

dataset('matriz_rol_permisos', [
    'Admin' => ['Admin', null],
    'Almacen' => ['Almacen', [
        'dashboard.ver',
        'items.ver', 'items.crear', 'items.editar',
        'items.cambiar_estado', 'items.mover',
        'items.revisar_devolucion',
        'reportes.ver',
        'categorias.ver', 'categorias.crear', 'categorias.editar',
        'ubicaciones.ver', 'ubicaciones.crear', 'ubicaciones.editar',
    ]],
    'Auditor' => ['Auditor', [
        'dashboard.ver',
        'items.ver',
        'reportes.ver',
        'categorias.ver',
        'ubicaciones.ver',
        'ventas.ver',
        'clientes.ver',
        'configuracion.ver',
    ]],
    'Ventas' => ['Ventas', [
        'dashboard.ver',
        'items.ver',
        'ventas.ver',
        'ventas.crear',
        'ventas.devolver',
        'clientes.ver',
        'clientes.crear',
        'clientes.editar',
    ]],
]);

it('cumple la matriz final de permisos por rol', function (string $rol, ?array $esperado) {
    $reales = Role::findByName($rol, 'web')
        ->permissions
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    if ($esperado === null) {
        $esperado = Permission::pluck('name')->sort()->values()->all();
    } else {
        $esperado = collect($esperado)->sort()->values()->all();
    }

    expect($reales)->toBe($esperado);
})->with('matriz_rol_permisos');

it('el fresh no crea permisos huérfanos de ventas, papelera ni catálogos', function () {
    expect(Permission::whereIn('name', [
        'catalogos.ver',
        'catalogos.editar',
        'movimientos.ver',
        'ventas.cerrar',
        'items.eliminar',
        'items.papelera',
        'items.restaurar',
        'items.borrar_definitivo',
    ])->count())->toBe(0);
});

it('la matriz tiene 30 permisos canónicos y Admin reúne los 30', function () {
    expect(Permission::count())->toBe(30);

    $admin = Role::findByName('Admin', 'web');
    expect($admin->permissions()->count())->toBe(30);
});

it('configuracion.editar está asignado exclusivamente al rol Admin', function () {
    $rolesConEditar = Role::permission('configuracion.editar')->pluck('name')->sort()->values()->all();

    expect($rolesConEditar)->toBe(['Admin']);
});

it('ningún rol no-Admin (legacy incluido) tiene configuracion.editar/ver; Auditor solo lectura', function () {
    foreach (['Almacen', 'Ventas', 'Operador', 'Consulta'] as $rol) {
        $rolModelo = Role::findByName($rol, 'web');
        expect($rolModelo->hasPermissionTo('configuracion.editar'))->toBeFalse();
        expect($rolModelo->hasPermissionTo('configuracion.ver'))->toBeFalse();
    }

    $auditor = Role::findByName('Auditor', 'web');
    expect($auditor->hasPermissionTo('configuracion.ver'))->toBeTrue();
    expect($auditor->hasPermissionTo('configuracion.editar'))->toBeFalse();
});

it('Ventas y Almacen reciben 403 en configuración (ver y editar)', function () {
    foreach (['Ventas', 'Almacen'] as $rol) {
        $user = User::factory()->create();
        $user->assignRole($rol);

        $this->actingAs($user)->get(route('configuracion.edit'))->assertForbidden();

        $this->actingAs($user)
            ->put(route('configuracion.update'), [
                'empresa_nombre' => 'Hackeada',
                'ticket_ancho' => 80,
            ])
            ->assertForbidden();
    }
});

it('el guard server-side rechaza otorgar configuracion.editar a un rol no Admin', function () {
    ConfiguracionAcceso::assertRolesSeguros([
        'Admin' => ['configuracion.ver', 'configuracion.editar'],
        'Auditor' => ['configuracion.ver'],
        'Ventas' => [],
        'Almacen' => [],
    ]);

    expect(fn () => ConfiguracionAcceso::assertRolesSeguros([
        'Admin' => ['configuracion.ver', 'configuracion.editar'],
        'Ventas' => ['configuracion.editar'],
    ]))->toThrow(\InvalidArgumentException::class);

    expect(fn () => ConfiguracionAcceso::assertRolConPermisoEditarSeguro('Auditor', ['configuracion.ver']))
        ->not->toThrow(\InvalidArgumentException::class);

    expect(fn () => ConfiguracionAcceso::assertRolConPermisoEditarSeguro('Consulta', ['configuracion.editar']))
        ->toThrow(\InvalidArgumentException::class);
});

it('la administración de usuarios sincroniza roles y nunca permisos directos ni escalamiento', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    // Intento de escalamiento: inyecta configuracion.editar en el payload.
    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name' => 'Ventas Nuevo',
            'email' => 'ventas-nuevo@example.com',
            'password' => 'password-segura-123',
            'roles' => ['Ventas'],
            'configuracion.editar' => 'on',
        ])
        ->assertRedirect(route('admin.users.index'));

    $nuevo = User::where('email', 'ventas-nuevo@example.com')->firstOrFail();
    expect($nuevo->hasRole('Ventas'))->toBeTrue();
    expect($nuevo->hasPermissionTo('configuracion.editar'))->toBeFalse();

    $this->actingAs($nuevo)
        ->put(route('configuracion.update'), [
            'empresa_nombre' => 'Hackeada',
            'ticket_ancho' => 80,
        ])
        ->assertForbidden();
});

it('el seeder es idempotente y no duplica roles ni permisos', function () {
    $rolesAntes = Role::count();
    $permisosAntes = Permission::count();
    $adminAntes = User::whereHas('roles', fn ($q) => $q->where('name', 'Admin'))->count();

    $this->seed(RolesAndAdminSeeder::class);
    $this->seed(RolesAndAdminSeeder::class);

    expect(Role::count())->toBe($rolesAntes);
    expect(Permission::count())->toBe($permisosAntes);
    expect(User::whereHas('roles', fn ($q) => $q->where('name', 'Admin'))->count())->toBe($adminAntes);

    $almacen = Role::findByName('Almacen', 'web');
    expect($almacen->permissions()->count())->toBe(14);
});

it('un admin reúne todos los permisos y puede administrar usuarios', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
        ->get('/admin/users')
        ->assertOk();
});

it('un rol sin permisos no accede a ninguna sección', function () {
    $user = User::factory()->create();
    $user->assignRole('Operador');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertForbidden();
});

it('el rol Auditor es de solo lectura en items', function () {
    $item = Item::create(['estado' => 'DISPONIBLE']);
    $user = User::factory()->create();
    $user->assignRole('Auditor');

    $this->actingAs($user)->get(route('items.show', $item))->assertOk();
    $this->actingAs($user)->get(route('items.label', $item))->assertOk();

    $this->actingAs($user)->get(route('items.edit', $item))->assertForbidden();
    $this->actingAs($user)->post(route('items.changeEstado', $item->id), ['estado' => 'BAJA'])->assertForbidden();
    $this->actingAs($user)->post(route('items.moveUbicacion', $item->id), ['ubicacion_id' => ''])->assertForbidden();
});

it('el rol Auditor no gestiona categorías ni usuarios', function () {
    $ubicacion = Ubicacion::create(['nombre' => 'Bodega A']);
    $user = User::factory()->create();
    $user->assignRole('Auditor');

    $this->actingAs($user)->get(route('categorias.create'))->assertForbidden();
    $this->actingAs($user)->get(route('ubicaciones.edit', $ubicacion))->assertForbidden();
    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
});

it('el rol Auditor sí consulta reportes', function () {
    $user = User::factory()->create();
    $user->assignRole('Auditor');

    $this->actingAs($user)->get(route('reports.index'))->assertOk();
});

it('el rol Ventas consulta items, scanner y etiqueta', function () {
    $item = Item::create(['estado' => 'DISPONIBLE']);
    $user = User::factory()->create();
    $user->assignRole('Ventas');

    $this->actingAs($user)->get(route('items.index'))->assertOk();
    $this->actingAs($user)->get(route('items.scan'))->assertOk();
    $this->actingAs($user)->get(route('items.show', $item))->assertOk();
    $this->actingAs($user)->get(route('items.label', $item))->assertOk();
});

it('el rol Ventas no puede escribir ni acceder a gestión', function () {
    $item = Item::create(['estado' => 'DISPONIBLE']);
    $ubicacion = Ubicacion::create(['nombre' => 'Bodega A']);
    Categoria::create(['nombre' => 'Impresora']);
    $user = User::factory()->create();
    $user->assignRole('Ventas');

    $this->actingAs($user)->get(route('items.create'))->assertForbidden();
    $this->actingAs($user)->get(route('items.edit', $item))->assertForbidden();

    $this->actingAs($user)->post(route('items.changeEstado', $item->id), ['estado' => 'BAJA'])->assertForbidden();
    $this->actingAs($user)->post(route('items.moveUbicacion', $item->id), ['ubicacion_id' => ''])->assertForbidden();

    $this->actingAs($user)->get(route('reports.index'))->assertForbidden();
    $this->actingAs($user)->get(route('categorias.index'))->assertForbidden();
    $this->actingAs($user)->get(route('ubicaciones.edit', $ubicacion))->assertForbidden();
    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
});

it('el rol Almacen gestiona categorías y ubicaciones sin eliminarlas', function () {
    $categoria = Categoria::create(['nombre' => 'Impresora']);
    $user = User::factory()->create();
    $user->assignRole('Almacen');

    $this->actingAs($user)->get(route('categorias.edit', $categoria))->assertOk();

    $this->actingAs($user)->delete(route('categorias.destroy', $categoria))->assertForbidden();
    $this->actingAs($user)->delete(route('ubicaciones.destroy', Ubicacion::create(['nombre' => 'Bodega A'])))->assertForbidden();
    $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
});

it('la ruta de usuarios depende del permiso, no del nombre del rol', function () {
    $conPermiso = User::factory()->create();
    $conPermiso->assignRole('Operador');
    $conPermiso->givePermissionTo('usuarios.ver');

    $this->actingAs($conPermiso)
        ->get(route('admin.users.index'))
        ->assertOk();
});

it('la ruta de usuarios bloquea a quien no tiene usuarios.ver aunque tenga rol', function () {
    $sinPermiso = User::factory()->create();
    $sinPermiso->assignRole('Operador');

    $this->actingAs($sinPermiso)
        ->get(route('admin.users.index'))
        ->assertForbidden();
});

it('el rol Ventas accede al POS y al listado de ventas y puede confirmar', function () {
    $user = User::factory()->create();
    $user->assignRole('Ventas');

    $this->actingAs($user)->get(route('pos.index'))->assertOk();
    $this->actingAs($user)->get(route('ventas.index'))->assertOk();

    // El middleware ventas.crear no bloquea: la validación sigue su curso (redirect).
    $this->actingAs($user)
        ->post(route('pos.checkout'), ['items' => [1], 'forma_pago' => 'EFECTIVO'])
        ->assertStatus(302);
});

it('el rol Auditor consulta el historico de ventas pero el POS queda bloqueado', function () {
    $vendedor = User::factory()->create();
    $venta = \App\Models\Venta::create([
        'user_id' => $vendedor->id,
        'total' => 100,
        'forma_pago' => 'EFECTIVO',
    ]);
    $user = User::factory()->create();
    $user->assignRole('Auditor');

    $this->actingAs($user)->get(route('ventas.index'))->assertOk();
    $this->actingAs($user)->get(route('ventas.show', $venta))->assertOk();

    // El POS es operativo: requiere ventas.crear, Auditor no lo tiene.
    $this->actingAs($user)->get(route('pos.index'))->assertForbidden();
    $this->actingAs($user)->post(route('pos.add'), ['codigo' => 'ITM-000001'])->assertForbidden();
    $this->actingAs($user)->post(route('pos.remove'), ['item_id' => 1])->assertForbidden();

    $this->actingAs($user)
        ->post(route('pos.checkout'), ['items' => [1], 'forma_pago' => 'EFECTIVO'])
        ->assertForbidden();
});

it('sin ventas.ver no se puede consultar el POS ni las ventas', function () {
    $user = User::factory()->create();
    $user->assignRole('Operador');

    $this->actingAs($user)->get(route('pos.index'))->assertForbidden();
    $this->actingAs($user)->get(route('ventas.index'))->assertForbidden();
});

it('con ventas.ver pero sin ventas.crear no se puede operar el POS', function () {
    $user = User::factory()->create();
    $user->assignRole('Operador');
    $user->givePermissionTo('ventas.ver');

    $this->actingAs($user)->get(route('pos.index'))->assertForbidden();
    $this->actingAs($user)->get(route('ventas.index'))->assertOk();

    $this->actingAs($user)
        ->post(route('pos.checkout'), ['items' => [1], 'forma_pago' => 'EFECTIVO'])
        ->assertForbidden();
});

it('el rol Almacen no accede al POS ni a las ventas', function () {
    $user = User::factory()->create();
    $user->assignRole('Almacen');

    $this->actingAs($user)->get(route('pos.index'))->assertForbidden();
    $this->actingAs($user)->get(route('ventas.index'))->assertForbidden();

    $this->actingAs($user)
        ->post(route('pos.checkout'), ['items' => [1], 'forma_pago' => 'EFECTIVO'])
        ->assertForbidden();
});

it('el rol Admin puede operar el POS y consultar ventas', function () {
    $user = User::factory()->create();
    $user->assignRole('Admin');

    $this->actingAs($user)->get(route('pos.index'))->assertOk();
    $this->actingAs($user)->get(route('ventas.index'))->assertOk();
});

it('el rol Ventas puede devolver equipos pero no cancelar la venta', function () {
    $venta = \App\Models\Venta::create([
        'user_id' => User::factory()->create()->id,
        'total' => 100,
        'forma_pago' => 'EFECTIVO',
    ]);
    $user = User::factory()->create();
    $user->assignRole('Ventas');

    $this->actingAs($user)->get(route('ventas.devolver', $venta))->assertOk();
    $this->actingAs($user)->post(route('ventas.devolver.store', $venta), [
        'detalles' => [],
        'motivo' => 'Cliente no conforme.',
        'forma_reembolso' => 'EFECTIVO',
    ])->assertStatus(302);

    $this->actingAs($user)->get(route('ventas.cancelar', $venta))->assertForbidden();
    $this->actingAs($user)->post(route('ventas.cancelar.store', $venta), [
        'motivo' => 'Pedido por error.',
    ])->assertForbidden();
});

it('el rol Admin accede a cancelar y devolver equipos', function () {
    $venta = \App\Models\Venta::create([
        'user_id' => User::factory()->create()->id,
        'total' => 100,
        'forma_pago' => 'EFECTIVO',
    ]);
    $user = User::factory()->create();
    $user->assignRole('Admin');

    $this->actingAs($user)->get(route('ventas.cancelar', $venta))->assertOk();
    $this->actingAs($user)->get(route('ventas.devolver', $venta))->assertOk();
});

it('el rol Auditor consulta documentos postventa pero no opera postventa', function () {
    $user = User::factory()->create();
    $user->assignRole('Auditor');
    $venta = \App\Models\Venta::create([
        'user_id' => $user->id,
        'total' => 100,
        'forma_pago' => 'EFECTIVO',
    ]);
    $documento = \App\Models\DocumentoPostventa::create([
        'venta_id' => $venta->id,
        'user_id' => $user->id,
        'tipo' => 'DEVOLUCION',
        'total' => 50,
        'motivo' => 'Prueba de consulta.',
    ]);

    $this->actingAs($user)->get(route('postventa.show', $documento))->assertOk();
    $this->actingAs($user)->get(route('postventa.print', $documento))->assertOk();

    $this->actingAs($user)->get(route('ventas.cancelar', $venta))->assertForbidden();
    $this->actingAs($user)->get(route('ventas.devolver', $venta))->assertForbidden();
});

it('el rol Almacen no opera postventa', function () {
    $venta = \App\Models\Venta::create([
        'user_id' => User::factory()->create()->id,
        'total' => 100,
        'forma_pago' => 'EFECTIVO',
    ]);
    $user = User::factory()->create();
    $user->assignRole('Almacen');

    $this->actingAs($user)->get(route('ventas.cancelar', $venta))->assertForbidden();
    $this->actingAs($user)->get(route('ventas.devolver', $venta))->assertForbidden();
});
