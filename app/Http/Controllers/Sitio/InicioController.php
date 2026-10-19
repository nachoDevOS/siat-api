<?php

namespace App\Http\Controllers\Sitio;

use App\Http\Controllers\Controller;
use App\Models\Sitio\SolicitudContacto;
use Illuminate\View\View;

/**
 * Portada publica del proveedor: una app React montada en una vista Blade.
 *
 * Todo lo que la app necesita del servidor (marca, contacto, rutas) viaja en
 * un JSON incrustado, asi la pagina no hace peticiones al cargar. El sitio no
 * publica precios ni planes.
 */
class InicioController extends Controller
{
    public function __invoke(): View
    {
        return view('sitio.app', [
            'datos' => [
                'marca' => config('sitio.marca'),
                'logo' => config('sitio.logo'),
                'whatsapp' => filled(config('sitio.whatsapp')) ? preg_replace('/\D/', '', (string) config('sitio.whatsapp')) : null,
                'correo' => config('sitio.correo'),
                'intereses' => SolicitudContacto::INTERESES,
                'rutas' => [
                    'contacto' => route('sitio.contacto'),
                    'documentacion' => route('docs.api'),
                    'coleccion' => route('docs.api.postman.coleccion'),
                ],
                'api_url' => url('/api/v1'),
                'mcp_url' => url('/mcp'),
            ],
        ]);
    }
}
