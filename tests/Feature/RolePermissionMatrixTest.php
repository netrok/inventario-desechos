<?php

use App\Models\Categoria;
use App\Models\Item;
use App\Models\Ubicacion;
use App\Models\User;
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
    ]],
    'Ventas' => ['Ventas', [
        'dashboard.ver',
        'items.ver',
        'ventas.ver',
        'ventas.crear',
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
    expect($almacen->permissions()->count())->toBe(13);
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
