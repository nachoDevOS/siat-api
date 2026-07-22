<?php

test('the application returns a successful response', function () {
    // La raiz redirige al panel de administracion.
    $response = $this->get('/');

    $response->assertRedirect(route('admin.empresas.index'));
});
