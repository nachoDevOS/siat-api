<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('un invitado que entra al panel es redirigido al login', function () {
    $this->get(route('admin.empresas.index'))->assertRedirect(route('login'));
});

test('el login muestra el formulario', function () {
    $this->get(route('login'))->assertOk()->assertSee('Ingresar');
});

test('un usuario puede iniciar sesion con credenciales validas', function () {
    $user = User::factory()->create(['password' => bcrypt('secreto123')]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'secreto123'])
        ->assertRedirect(route('admin.empresas.index'));

    $this->assertAuthenticatedAs($user);
});

test('credenciales invalidas no inician sesion', function () {
    $user = User::factory()->create(['password' => bcrypt('secreto123')]);

    $this->from(route('login'))
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'malo'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('un usuario logueado puede cerrar sesion', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
