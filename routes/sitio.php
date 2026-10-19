<?php

use App\Http\Controllers\Sitio\ContactoController;
use App\Http\Controllers\Sitio\InicioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Sitio publico del proveedor
|--------------------------------------------------------------------------
| La portada del dominio (app React en resources/js/sitio) y su formulario de
| contacto. Va aparte de web.php (panel y facturas publicas) para que todo lo
| del sitio quede junto: controladores en Controllers/Sitio, modelo en
| Models/Sitio y vista en views/sitio.
*/

Route::get('/', InicioController::class)->name('sitio.inicio');

Route::post('contacto', [ContactoController::class, 'store'])
    ->middleware('throttle:sitio-contacto')
    ->name('sitio.contacto');
