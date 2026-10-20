<?php

namespace App\Mcp\Tools;

use App\Exceptions\CufdVencidoException;
use App\Exceptions\FacturaInvalidaException;
use App\Http\Requests\EmitirFacturaRequest;
use App\Http\Resources\FacturaResource;
use App\Services\Factura\EmisorFactura;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Emite una factura de compra-venta (sector 1) y devuelve su CUF y enlaces para imprimir. Recibe la venta con el mismo formato que POST /api/v1/facturas. Usar siempre referencia_externa: repetirla devuelve la misma factura en vez de duplicarla.')]
class EmitirFactura extends HerramientaSiat
{
    public function handle(Request $request, EmisorFactura $emisor): Response
    {
        if ($error = $this->sinPermiso('facturas:emitir')) {
            return $error;
        }

        if (! $this->empresa()->puedeEmitirPorApi()) {
            return Response::error('La empresa no esta habilitada para emitir.');
        }

        $reglas = new EmitirFacturaRequest;
        $validador = Validator::make((array) $request->get('venta', []), $reglas->rules(), $reglas->messages());

        if ($validador->fails()) {
            return Response::error('Venta invalida: '.implode(' ', $validador->errors()->all()));
        }

        try {
            $factura = $emisor->emitir($this->empresa(), $validador->validated());
        } catch (FacturaInvalidaException $e) {
            return Response::error('La venta no paso las validaciones: '.implode(' ', $e->errores));
        } catch (CufdVencidoException $e) {
            return Response::error('El SIAT no esta disponible: '.$e->getMessage());
        } catch (ModelNotFoundException) {
            return Response::error('No existe ese punto de venta activo en la sucursal indicada.');
        }

        return $this->json(new FacturaResource($factura->load('puntoVenta.sucursal')));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'venta' => $schema->object()
                ->description('Venta: sucursal, punto_venta, referencia_externa, comprador {tipo_documento, numero_documento, razon_social, complemento?, email?}, metodo_pago, codigo_excepcion?, descuento_global?, items[] {codigo_producto_sin, descripcion, cantidad, unidad_medida, precio_unitario (NETO, sin IVA: se suma el 13%), descuento?, codigo_interno?}.')
                ->required(),
        ];
    }
}
