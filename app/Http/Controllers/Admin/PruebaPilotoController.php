<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CasoPrueba;
use App\Models\EjecucionPrueba;
use App\Models\Empresa;
use App\Services\Panel\RequisitosEtapa;
use App\Services\Pruebas\EjecutorPruebas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Panel de pruebas piloto (fase 3, por cliente). Muestra los 17 pasos con su
 * estado, permite correrlos de a uno o todos en orden, y deja ver la respuesta
 * cruda de cada uno.
 *
 * Los botones solo se habilitan cuando estan los requisitos manuales del portal
 * del SIN (token y certificado), para no depurar errores de token que en
 * realidad son de tramite (seccion 12.1).
 */
class PruebaPilotoController extends Controller
{
    public function show(Empresa $empresa, RequisitosEtapa $requisitos): View
    {
        $casos = CasoPrueba::where('fase', CasoPrueba::FASE_PILOTO)->orderBy('orden')->get();

        // Ultima ejecucion de cada caso para esta empresa.
        $ultimas = EjecucionPrueba::where('empresa_id', $empresa->id)
            ->get()
            ->groupBy('caso_id')
            ->map(fn ($grupo) => $grupo->sortByDesc('ejecutado_en')->first());

        return view('admin.pruebas.index', [
            'empresa' => $empresa,
            'casos' => $casos,
            'ultimas' => $ultimas,
            'requisitos' => $this->requisitosPrevios($empresa),
            'progreso' => $requisitos->progresoPiloto($empresa),
        ]);
    }

    /**
     * Corre la secuencia completa. Se detiene en el primer caso obligatorio
     * que falle para no arrastrar errores en cadena.
     */
    public function ejecutar(Empresa $empresa, EjecutorPruebas $ejecutor): RedirectResponse
    {
        $resultado = $ejecutor->ejecutarSecuencia($empresa, CasoPrueba::FASE_PILOTO);

        $mensaje = $resultado['fallo'] === null
            ? "Secuencia completa: {$resultado['ejecutados']} casos ejecutados."
            : "Se detuvo en: {$resultado['fallo']}.";

        return $this->volver($empresa, $mensaje);
    }

    /**
     * Corre un solo paso. Sirve para reintentar el que fallo sin repetir los
     * anteriores, que es lo habitual mientras se depura el piloto.
     */
    public function ejecutarCaso(Empresa $empresa, CasoPrueba $caso, EjecutorPruebas $ejecutor): RedirectResponse
    {
        $ejecucion = $ejecutor->ejecutarCaso($empresa, $caso);

        $resultado = $ejecucion->estado === EjecucionPrueba::ESTADO_EXITOSO ? 'OK' : 'con error';

        return $this->volver($empresa, "Paso {$caso->orden} ({$caso->nombre}): {$resultado}.");
    }

    /**
     * Guarda el payload_ejemplo de un caso.
     *
     * Los pasos 11 al 16 emiten documentos reales y los datos que llevan los
     * define la especificacion que el SIN genera para cada contribuyente. Se
     * cargan desde aca para no tener que tocar la base a mano, y por eso los
     * casos viven en base de datos y no en codigo.
     */
    public function guardarPayload(Request $request, Empresa $empresa, CasoPrueba $caso): RedirectResponse
    {
        $datos = $request->validate([
            'payload_ejemplo' => ['nullable', 'string', 'json'],
        ], [
            'payload_ejemplo.json' => 'El payload debe ser un JSON valido.',
        ]);

        $caso->update([
            'payload_ejemplo' => blank($datos['payload_ejemplo'])
                ? null
                : json_decode($datos['payload_ejemplo'], true),
        ]);

        return $this->volver($empresa, "Payload del paso {$caso->orden} guardado.");
    }

    /**
     * Requisitos previos verificables desde aca. Los otros dos (asociacion y
     * confirmacion del contribuyente) son tramites del portal del SIN y no
     * dejan rastro consultable.
     *
     * @return array{token: bool, certificado: bool}
     */
    private function requisitosPrevios(Empresa $empresa): array
    {
        return [
            'token' => filled($empresa->token_delegado),
            'certificado' => $empresa->certificados()->where('activo', true)->exists(),
        ];
    }

    private function volver(Empresa $empresa, string $mensaje): RedirectResponse
    {
        return redirect()->route('admin.pruebas.show', $empresa)->with('estado', $mensaje);
    }
}
