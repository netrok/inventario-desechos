<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function perfilUsuario(): User
{
    return User::factory()->create([
        'name' => 'Usuario Demo',
        'email' => 'usuario@example.com',
    ]);
}

it('el perfil está completamente en español para un usuario autenticado', function () {
    $this->actingAs(perfilUsuario())
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertSee('Perfil')
        ->assertSee('Información del perfil')
        ->assertSee('Nombre')
        ->assertSee('Correo electrónico')
        ->assertSee('Guardar')
        ->assertSee('Cambiar contraseña')
        ->assertSee('Contraseña actual')
        ->assertSee('Nueva contraseña')
        ->assertSee('Confirmar contraseña')
        ->assertDontSee('Profile Information', false)
        ->assertDontSee('Update Password', false)
        ->assertDontSee('Current Password', false)
        ->assertDontSee('New Password', false)
        ->assertDontSee('Confirm Password', false);
});

it('el título del perfil usa el branding B12 sin mostrar Laravel', function () {
    $response = $this->actingAs(perfilUsuario())
        ->get(route('profile.edit'))
        ->assertOk();

    $content = $response->getContent();

    expect($content)->toContain('<title>Perfil | Inventario ReUse</title>');
    expect($content)->not->toContain('<title>Laravel');
    expect($content)->not->toContain('Laravel Breeze', false);
    expect($content)->not->toContain('>Laravel<', false);
});

it('el perfil no reintroduce el flujo de eliminación propia de cuenta', function () {
    $usuario = perfilUsuario();

    $this->actingAs($usuario)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('Delete Account', false)
        ->assertDontSee('Eliminar cuenta');

    // No existe endpoint de auto-eliminación: DELETE no está registrado (405).
    $this->actingAs($usuario)
        ->delete('/profile')
        ->assertStatus(405);
});
