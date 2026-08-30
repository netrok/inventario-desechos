<?php

use App\Models\User;
use Database\Seeders\RolesAndAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

it('login muestra el crédito del desarrollador', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Inventario ReUse')
        ->assertSee('Desarrollado por Ernesto')
        ->assertSee('2026')
        ->assertDontSee('Laravel', false)
        ->assertDontSee('Laravel Breeze', false);
});

it('el layout autenticado muestra el footer de créditos', function () {
    $this->seed(RolesAndAdminSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $user = User::factory()->create();
    $user->givePermissionTo('dashboard.ver');

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Inventario ReUse')
        ->assertSee('Desarrollado por Ernesto')
        ->assertSee('2026');
});

it('la fuente central del autor es pública y no requiere variable de entorno', function () {
    expect(config('app.author'))->toBe('Ernesto');
    expect(config('app.copyright_year'))->toBe('2026');
    expect(config('app.name'))->toBe('Inventario ReUse');
});

it('la página Acerca del sistema es visible para un usuario autenticado', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('acerca'))
        ->assertOk()
        ->assertSee('Inventario ReUse')
        ->assertSee('Diseño y desarrollo')
        ->assertSee('Ernesto');
});

it('la página Acerca del sistema no expone información técnica sensible', function () {
    $content = $this->actingAs(User::factory()->create())
        ->get(route('acerca'))
        ->assertOk()
        ->getContent();

    expect($content)->not->toContain('APP_KEY');
    expect($content)->not->toContain((string) config('app.key'));
    expect($content)->not->toContain(env('DB_DATABASE'));
    expect($content)->not->toContain(env('DB_HOST'));
    expect($content)->not->toContain('POSTGRES');
    expect($content)->not->toContain('nginx', false);
});
