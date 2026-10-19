<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida un lote de ventas para POST /api/v1/facturas/lote: hasta 20, cada
 * una con las mismas reglas que POST /facturas.
 *
 * Cada venta EXIGE su referencia_externa: en un lote no hay una cabecera de
 * idempotencia por venta, y sin referencia un reintento del lote entero
 * duplicaria las que ya salieron.
 */
class EmitirLoteRequest extends FormRequest
{
    /** Tope de ventas por peticion: cada una firma un XML y no debe pasar el timeout HTTP. */
    public const MAXIMO = 20;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reglas = ['ventas' => ['required', 'array', 'min:1', 'max:'.self::MAXIMO]];

        foreach ((new EmitirFacturaRequest)->rules() as $campo => $regla) {
            $reglas["ventas.*.{$campo}"] = $regla;
        }

        $reglas['ventas.*.referencia_externa'] = ['required', 'string', 'max:100', 'distinct'];

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $mensajes = [];

        foreach ((new EmitirFacturaRequest)->messages() as $clave => $mensaje) {
            $mensajes["ventas.*.{$clave}"] = $mensaje;
        }

        return $mensajes + [
            'ventas.*.referencia_externa.required' => 'En un lote cada venta necesita su referencia_externa (idempotencia).',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ventas(): array
    {
        return array_values($this->validated()['ventas']);
    }
}
