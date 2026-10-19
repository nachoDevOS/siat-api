<?php

namespace App\Http\Requests;

use App\Services\Factura\AnuladorFactura;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Mismo control que el panel: un motivo fuera del catalogo dejaba la
            // factura ANULADA hasta que el SIN lo rechazaba, y entonces se
            // revertia con un webhook de anulacion rechazada.
            'motivo' => ['required', 'integer', Rule::in(array_keys(app(AnuladorFactura::class)->motivos()))],
        ];
    }
}
