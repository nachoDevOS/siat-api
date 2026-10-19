<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\V1\EstadoController;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Indica si la empresa puede facturar ahora: ambiente (PILOTO = pruebas sin validez fiscal, o PRODUCCION), estado ante el SIN y puntos de venta listos. Llamarla antes de emitir.')]
class EstadoIntegracion extends HerramientaSiat
{
    public function handle(Request $request): Response
    {
        if ($error = $this->sinPermiso('estado:leer')) {
            return $error;
        }

        // Misma respuesta que GET /api/v1/estado.
        return $this->json(app(EstadoController::class)(request())->getData(true));
    }
}
