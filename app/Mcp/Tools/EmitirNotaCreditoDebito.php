<?php

namespace App\Mcp\Tools;

use App\Exceptions\CufdVencidoException;
use App\Exceptions\FacturaInvalidaException;
use App\Http\Requests\EmitirNotaCreditoDebitoRequest;
use App\Http\Resources\FacturaResource;
use App\Services\Factura\EmisorNotaCreditoDebito;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Emite una nota de credito-debito (devolucion total o parcial) sobre una factura VALIDADA. Solo indicar la linea de la factura (desde 1) y la cantidad devuelta; los montos y el 13% los calcula el sistema.')]
class EmitirNotaCreditoDebito extends HerramientaSiat
{
    public function handle(Request $request, EmisorNotaCreditoDebito $emisor): Response
    {
        if ($error = $this->sinPermiso('facturas:anular')) {
            return $error;
        }

        if (! $this->empresa()->puedeEmitirPorApi()) {
            return Response::error('La empresa no esta habilitada para emitir.');
        }

        $origen = $this->buscarFactura((string) $request->get('cuf'));

        if ($origen === null) {
            return Response::error('No existe una factura de la empresa con ese CUF.');
        }

        $validador = Validator::make(
            ['items' => $request->get('items', []), 'referencia_externa' => $request->get('referencia_externa')],
            (new EmitirNotaCreditoDebitoRequest)->rules(),
        );

        if ($validador->fails()) {
            return Response::error('Devolucion invalida: '.implode(' ', $validador->errors()->all()));
        }

        try {
            $nota = $emisor->emitir($origen, $validador->validated());
        } catch (FacturaInvalidaException $e) {
            return Response::error('La devolucion no se puede emitir: '.implode(' ', $e->errores));
        } catch (CufdVencidoException $e) {
            return Response::error('El SIAT no esta disponible: '.$e->getMessage());
        }

        return $this->json(new FacturaResource($nota->load('puntoVenta.sucursal', 'facturaOrigen')));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'cuf' => $schema->string()->description('CUF de la factura que se devuelve.')->required(),
            'items' => $schema->array()->items($schema->object([
                'linea' => $schema->integer()->required(),
                'cantidad' => $schema->number()->required(),
            ]))->required(),
            'referencia_externa' => $schema->string()->description('Id de la devolucion en su sistema (idempotencia).'),
        ];
    }
}
