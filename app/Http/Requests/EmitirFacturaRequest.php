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
        // La empresa ya fue autenticada por el middleware AutenticarToken.
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
            // 1 = el NIT del comprador no valida pero se emite igual. Solo
            // tiene sentido con tipo de documento 5 (NIT); lo controla
            // ValidadorFactura.
            'codigo_excepcion' => ['nullable', 'integer', 'in:0,1'],

            'metodo_pago' => ['required', 'integer'],
            'numero_tarjeta' => ['nullable', 'string', 'max:20'],
            // Solo bolivianos por ahora. El SIN reparte los montos entre
            // montoTotal (Bs) y montoTotalMoneda (moneda extranjera) segun el
            // tipo de cambio, y esa regla no esta verificada contra un
            // sistema en produccion (ventas tambien factura solo en Bs).
            // Mejor rechazar que emitir montos que el SIN observe.
            'moneda' => ['nullable', 'integer', 'in:1'],
            'tipo_cambio' => ['nullable', 'numeric', 'size:1'],
            'descuento_global' => ['nullable', 'numeric', 'min:0'],
            'gift_card' => ['nullable', 'numeric', 'min:0'],
            // El sector 1 (compra-venta) no tiene anticipo. Se rechaza en vez de
            // ignorarlo: el cliente que lo manda espera que baje el total, y
            // tirarlo en silencio le facturaria un monto distinto al cobrado.
            'anticipo' => ['nullable', 'numeric', 'size:0'],
            'usuario' => ['nullable', 'string', 'max:100'],
            // Opcional: si no viene, se toma del catalogo de leyendas del NIT
            // segun la actividad economica del primer item.
            'leyenda' => ['nullable', 'string', 'max:500'],

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
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'anticipo.size' => 'La factura de compra-venta (sector 1) no admite anticipo.',
            'moneda.in' => 'Por ahora solo se factura en bolivianos (moneda 1).',
            'tipo_cambio.size' => 'Facturando en bolivianos el tipo de cambio es 1.',
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
