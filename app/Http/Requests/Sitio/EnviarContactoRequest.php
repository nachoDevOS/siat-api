<?php

namespace App\Http\Requests\Sitio;

use App\Models\Sitio\SolicitudContacto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valida el formulario de contacto del sitio publico.
 *
 * 'sitio_web' es una trampa para bots: el campo esta oculto en el formulario,
 * una persona no lo llena y un bot que completa todo, si.
 */
class EnviarContactoRequest extends FormRequest
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
            'nombre' => ['required', 'string', 'max:120'],
            'empresa' => ['nullable', 'string', 'max:160'],
            'correo' => ['required', 'email', 'max:160'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'interes' => ['required', Rule::in(array_keys(SolicitudContacto::INTERESES))],
            'mensaje' => ['required', 'string', 'min:10', 'max:2000'],
            'sitio_web' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nombre.required' => 'Escriba su nombre.',
            'correo.required' => 'Escriba su correo.',
            'correo.email' => 'El correo no es valido.',
            'interes.required' => 'Elija que busca.',
            'mensaje.required' => 'Cuentenos brevemente que necesita.',
            'mensaje.min' => 'Cuentenos un poco mas (al menos 10 caracteres).',
            'sitio_web.prohibited' => 'No se pudo enviar el formulario.',
        ];
    }
}
