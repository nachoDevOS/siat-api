<?php

namespace App\Http\Controllers\Sitio;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sitio\EnviarContactoRequest;
use App\Models\Sitio\SolicitudContacto;
use Illuminate\Http\JsonResponse;

/**
 * Recibe el formulario de contacto del sitio publico (lo envia la app React
 * por fetch) y lo deja para el administrador en el panel.
 */
class ContactoController extends Controller
{
    public function store(EnviarContactoRequest $request): JsonResponse
    {
        $datos = $request->safe()->except('sitio_web');

        SolicitudContacto::create($datos + ['ip' => $request->ip()]);

        return response()->json([
            'exito' => true,
            'mensaje' => 'Recibimos su mensaje. Le respondemos a la brevedad.',
        ], 201);
    }
}
