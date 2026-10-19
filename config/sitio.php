<?php

/*
|--------------------------------------------------------------------------
| Sitio publico (la portada del dominio)
|--------------------------------------------------------------------------
| Lo que muestra la vista publica del proveedor. Los datos de contacto viven
| en el .env: si quedan vacios, el sitio simplemente no los muestra (no se
| inventa un numero). El sitio NUNCA muestra precios ni planes.
*/

return [

    'marca' => env('SITIO_MARCA', 'Solución Digital'),

    // Logo de la marca. Por defecto, el del sitio de la empresa.
    'logo' => env('SITIO_LOGO') ?: 'https://www.soluciondigital.dev/image/logo.png',

    // Numero de WhatsApp con codigo de pais y sin signos, ej. 59170000000.
    'whatsapp' => env('SITIO_WHATSAPP'),

    'correo' => env('SITIO_CORREO'),

    // Solicitudes de contacto por minuto y por IP: frena el spam del formulario.
    'limite_contacto' => (int) env('SITIO_LIMITE_CONTACTO', 5),

];
