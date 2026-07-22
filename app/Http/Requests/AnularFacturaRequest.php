<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida la anulacion de una factura: solo requiere el motivo, que es uno de
 * los codigos del catalogo motivos_anulacion del SIN.
 */
class AnularFacturaRequest extends FormRequest
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
            'motivo' => ['required', 'integer'],
        ];
    }
}
