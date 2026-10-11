<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\LogApi;
use App\Services\Webhooks\DestinoWebhook;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Acceso API" de una empresa: todo lo que su sistema de ventas necesita para
 * conectarse, en una pantalla propia y separada de la ficha.
 *
 *   1. Datos de conexion que se le entregan (URL base, codigos).
 *   2. Tokens de Sanctum (crear y revocar los maneja TokenApiController).
 *   3. Webhook: URL y secreto con el que se firman los avisos.
 *   4. Sus ultimas llamadas a la API.
 *
 * Antes estaba todo dentro de la ficha, mezclado con certificado, sucursales,
 * catalogos y piloto.
 */
class AccesoApiController extends Controller
{
    public function show(Empresa $empresa): View
    {
        $empresa->load(['sucursales' => fn ($q) => $q->orderBy('codigo_sucursal'), 'sucursales.puntosVenta' => fn ($q) => $q
            ->where('activo', true)->orderBy('codigo_punto_venta')]);

        return view('admin.empresas.api', [
            'empresa' => $empresa,
            'baseUrl' => rtrim((string) config('app.url'), '/').'/api/v1',
            'tokens' => $empresa->tokens()->latest('id')->get(),
            'perfiles' => config('siat.api.perfiles'),
            'llamadas' => LogApi::where('empresa_id', $empresa->id)->latest('id')->limit(30)->get(),
        ]);
    }

    /**
     * Guarda (o borra, si viene vacia) la URL del webhook de la empresa.
     */
    public function actualizarWebhook(Request $request, Empresa $empresa): RedirectResponse
    {
        $datos = $request->validate([
            // Se valida el destino aca y no solo al notificar: asi el operador
            // ve el error al guardar en vez de descubrirlo por webhooks mudos.
            'webhook_url' => ['nullable', 'url', 'max:255', function (string $atributo, mixed $valor, Closure $falla) {
                $motivo = app(DestinoWebhook::class)->motivoRechazo((string) $valor);

                if ($motivo !== null) {
                    $falla($motivo);
                }
            }],
        ]);

        $empresa->update(['webhook_url' => $datos['webhook_url'] ?? null]);

        return redirect()
            ->route('admin.empresas.api', $empresa)
            ->with('estado', filled($datos['webhook_url'] ?? null) ? 'Webhook guardado.' : 'Webhook quitado: la empresa ya no recibe avisos.');
    }
}
