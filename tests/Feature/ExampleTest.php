<?php

test('the application returns a successful response', function () {
    // La raiz redirige al tablero del panel de administracion.
    $response = $this->get('/');

    $response->assertRedirect(route('admin.dashboard'));
});
