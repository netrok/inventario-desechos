<?php

use App\Models\User;
use App\Support\Titulos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('Titulos::app nunca devuelve Laravel', function () {
    config()->set('app.name', 'Laravel');
    expect(Titulos::app())->toBe('Inventario ReUse');

    config()->set('app.name', '');
    expect(Titulos::app())->toBe('Inventario ReUse');

    config()->set('app.name', 'Inventario ReUse');
    expect(Titulos::app())->toBe('Inventario ReUse');
});

it('Titulos::componer combina sección con el nombre de la app', function () {
    config()->set('app.name', 'Inventario ReUse');
    expect(Titulos::componer('Ventas'))->toBe('Ventas | Inventario ReUse');
    expect(Titulos::componer(null))->toBe('Inventario ReUse');
    expect(Titulos::componer(''))->toBe('Inventario ReUse');
});

it('la vista de login usa la marca y no muestra branding de Laravel', function () {
    Permission::findOrCreate('dashboard.ver', 'web');

    $user = User::factory()->create();

    // El login (no autenticado) debe mostrar la marca del sistema.
    $this->get('/login')
        ->assertOk()
        ->assertSee('Inventario ReUse')
        ->assertDontSee('Laravel');
});

it('el favicon de la aplicación existe', function () {
    $this->assertFileExists(public_path('favicon.svg'));
});

it('el login es independiente de la configuración de DB de la demo', function () {
    // El login no depende de tablas del negocio: solo responde 200.
    $this->get('/login')->assertOk();
});
