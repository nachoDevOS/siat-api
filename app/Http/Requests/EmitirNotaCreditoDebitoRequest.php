<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida una devolucion que llega por POST /api/v1/facturas/{cuf}/notas.
 *
 * Solo se pide QUE se devuelve: la linea de la factura (posicion desde 1, en
 * el orden en que se envio en la venta) y la cantidad. Precios, descuentos y
 * montos salen de la factura original; el control de cantidades contra lo
 * vendido lo hace EmisorNotaCreditoDebito.
 */
class EmitirNotaCreditoDebitoRequest extends FormRequest
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
            'referencia_externa' => ['nullable', 'string', 'max:100'],
            'usuario' => ['nullable', 'string', 'max:100'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.linea' => ['required', 'integer', 'min:1'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
        ];
    }

    /**
     * Devolucion ya validada, lista para EmisorNotaCreditoDebito.
     *
     * @return array{items: list<array{linea: int, cantidad: int|float|string}>, referencia_externa?: string|null, usuario?: string|null}
     */
    public function devolucion(): array
    {
        return $this->validated();
    }
}
