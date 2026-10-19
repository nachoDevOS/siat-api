<?php

namespace App\Http\Requests;

use App\Services\Factura\AnuladorFactura;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valida el registro de una anulacion administrativa: el numero de la
 * resolucion con la que el SIN la aprobo y el motivo del catalogo.
 */
class AnularFacturaAdministrativamenteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'resolucion' => ['required', 'string', 'max:100'],
            'motivo' => ['required', 'integer', Rule::in(array_keys(app(AnuladorFactura::class)->motivos()))],
        ];
    }
}
