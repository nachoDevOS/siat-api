<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\TokenApi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Token de API de una empresa. Regla del proveedor: UN token por empresa, que
 * el sistema de ventas del cliente usa para todas sus sucursales y puntos de
 * venta. Generar uno nuevo revoca el anterior (Empresa::reemplazarTokenApi).
 */
class TokenApiController extends Controller
{
    public function store(Request $request, Empresa $empresa): RedirectResponse
    {
        $datos = $request->validate([
            'perfil' => ['required', Rule::in(array_keys(config('siat.api.perfiles')))],
            'vence_el' => ['nullable', 'date', 'after:today'],
            // Una IP por linea o separadas por coma.
            'ips_permitidas' => ['nullable', 'string', 'max:1000'],
        ]);

        $ips = $this->listaIps($datos['ips_permitidas'] ?? null);

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return back()->withInput()->with('error', "La IP '{$ip}' no es valida.");
            }
        }

        $teniaToken = $empresa->tokens()->exists();

        $nuevo = $empresa->reemplazarTokenApi(
            $datos['perfil'],
            filled($datos['vence_el'] ?? null) ? Carbon::parse($datos['vence_el'])->endOfDay() : null,
            $ips,
        );

        $mensaje = $teniaToken
            ? 'Token regenerado: el anterior ya NO funciona. Copia el nuevo ahora (no se vuelve a mostrar) y entregalo al cliente.'
            : 'Token generado. Copialo ahora: no se vuelve a mostrar.';

        return redirect()
            ->route('admin.empresas.api', $empresa)
            ->with('token_api', $nuevo->plainTextToken)
            ->with('estado', $mensaje);
    }

    /**
     * Revoca un token: el sistema que lo usaba deja de poder llamar a la API
     * en la peticion siguiente.
     */
    public function destroy(Empresa $empresa, TokenApi $token): RedirectResponse
    {
        // El token tiene que ser de ESTA empresa: con solo cambiar el id en la
        // URL no se puede revocar el de otra.
        abort_unless(
            $token->tokenable_type === $empresa->getMorphClass() && (int) $token->tokenable_id === $empresa->id,
            404,
        );

        $nombre = $token->name;
        $token->delete();

        return redirect()
            ->route('admin.empresas.api', $empresa)
            ->with('estado', "Token '{$nombre}' revocado.");
    }

    /**
     * @return list<string>
     */
    private function listaIps(?string $texto): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[\s,]+/', (string) $texto) ?: [],
        )));
    }
}
