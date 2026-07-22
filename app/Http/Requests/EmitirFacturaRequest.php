<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida el JSON de una venta que llega por POST /api/v1/facturas.
 * La autenticacion ya la hizo el middleware; aca solo se valida la forma.
 */
class EmitirFacturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // La empresa ya fue autenticada por el middleware AutenticarApiKey.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'sucursal' => ['required', 'integer', 'min:0'],
            'punto_venta' => ['required', 'integer', 'min:0'],
            'referencia_externa' => ['nullable', 'string', 'max:100'],

            'comprador' => ['required', 'array'],
            'comprador.tipo_documento' => ['required', 'integer'],
            'comprador.numero_documento' => ['required', 'string', 'max:50'],
            'comprador.complemento' => ['nullable', 'string', 'max:5'],
            'comprador.razon_social' => ['required', 'string', 'max:255'],
            'comprador.email' => ['nullable', 'email', 'max:255'],

            'metodo_pago' => ['required', 'integer'],
            'numero_tarjeta' => ['nullable', 'string', 'max:20'],
            'moneda' => ['nullable', 'integer'],
            'tipo_cambio' => ['nullable', 'numeric', 'min:0'],
            'descuento_global' => ['nullable', 'numeric', 'min:0'],
            'gift_card' => ['nullable', 'numeric', 'min:0'],
            'anticipo' => ['nullable', 'numeric', 'min:0'],
            'usuario' => ['nullable', 'string', 'max:100'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.codigo_producto_sin' => ['required', 'integer'],
            'items.*.codigo_interno' => ['nullable', 'string', 'max:100'],
            'items.*.descripcion' => ['required', 'string', 'max:500'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.unidad_medida' => ['required', 'integer'],
            'items.*.precio_unitario' => ['required', 'numeric', 'min:0'],
            'items.*.descuento' => ['nullable', 'numeric', 'min:0'],
            'items.*.numero_serie' => ['nullable', 'string', 'max:100'],
            'items.*.numero_imei' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Venta ya validada, lista para el EmisorFactura.
     *
     * @return array<string, mixed>
     */
    public function venta(): array
    {
        return $this->validated();
    }
}
