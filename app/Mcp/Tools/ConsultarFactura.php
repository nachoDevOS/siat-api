<?php

namespace App\Mcp\Tools;

use App\Http\Resources\FacturaResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Estado actual de una factura o nota de credito-debito por su CUF: VALIDADA, OBSERVADA (con el motivo), ANULADA..., y sus enlaces publicos.')]
class ConsultarFactura extends HerramientaSiat
{
    public function handle(Request $request): Response
    {
        if ($error = $this->sinPermiso('facturas:leer')) {
            return $error;
        }

        $factura = $this->buscarFactura((string) $request->get('cuf'));

        return $factura === null
            ? Response::error('No existe una factura de la empresa con ese CUF.')
            : $this->json(new FacturaResource($factura));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['cuf' => $schema->string()->description('CUF de la factura o nota.')->required()];
    }
}
