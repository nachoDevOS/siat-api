<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CasoPrueba;
use App\Models\EjecucionPrueba;
use App\Models\Empresa;
use App\Services\Pruebas\EjecutorPruebas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Panel de pruebas piloto (fase 3, por cliente). Muestra los requisitos previos
 * y la secuencia de casos con su ultima ejecucion.
 *
 * El boton de iniciar solo se habilita cuando estan los cuatro requisitos
 * manuales del portal del SIN (asociacion, confirmacion, token, certificado),
 * para no depurar errores de token que en realidad son de tramite (seccion 12.1).
 */
class PruebaPilotoController extends Controller
{
    public function show(Empresa $empresa): View
    {
        $casos = CasoPrueba::where('fase', CasoPrueba::FASE_PILOTO)->orderBy('orden')->get();

        // Ultima ejecucion de cada caso para esta empresa.
        $ultimas = EjecucionPrueba::where('empresa_id', $empresa->id)
            ->get()
            ->groupBy('caso_id')
            ->map(fn ($grupo) => $grupo->sortByDesc('ejecutado_en')->first());

        // Requisitos previos: token cargado y certificado activo son verificables.
        $requisitos = [
            'token' => filled($empresa->token_delegado),
            'certificado' => $empresa->certificados()->where('activo', true)->exists(),
        ];

        return view('admin.pruebas.index', compact('empresa', 'casos', 'ultimas', 'requisitos'));
    }

    public function ejecutar(Request $request, Empresa $empresa, EjecutorPruebas $ejecutor): RedirectResponse
    {
        // Se corre la secuencia completa; se detiene en el primer fallo obligatorio.
        $resultado = $ejecutor->ejecutarSecuencia($empresa, CasoPrueba::FASE_PILOTO);

        // Si toda la secuencia paso, la empresa avanza a PILOTO_APROBADO.
        if ($resultado['fallo'] === null && $resultado['ejecutados'] > 0) {
            $empresa->update(['estado' => Empresa::ESTADO_PILOTO_APROBADO]);
        }

        $mensaje = $resultado['fallo'] === null
            ? "Secuencia completa: {$resultado['ejecutados']} casos ejecutados."
            : "Se detuvo en: {$resultado['fallo']}.";

        return redirect()
            ->route('admin.pruebas.show', $empresa)
            ->with('estado', $mensaje);
    }
}
