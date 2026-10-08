<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SiatException;
use App\Http\Controllers\Controller;
use App\Jobs\EjecutarCasoPrueba;
use App\Models\CasoPrueba;
use App\Models\EjecucionPrueba;
use App\Models\Empresa;
use App\Services\Panel\RequisitosEtapa;
use App\Services\Pruebas\EjecutorPruebas;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Panel de pruebas piloto (fase 3, por cliente). Muestra las etapas tal como
 * las lista el portal del SIN (Seguimiento de Autorizacion de Sistemas), con
 * sus pruebas, esperadas y correctas, y permite correr una prueba, completarla
 * por cola o lanzar la etapa entera.
 *
 * El bloqueo es por lo que cada prueba usa de verdad: sin token no corre nada;
 * sin certificado, solo se frenan las que firman (seccion 12.1).
 */
class PruebaPilotoController extends Controller
{
    /**
     * Llamadas que una etapa puede hacer dentro del request. Por encima, va a
     * la cola: 10 llamadas de hasta 4 s ya rozan el timeout de PHP.
     */
    private const MAXIMO_SINCRONO = 10;

    public function show(Empresa $empresa, RequisitosEtapa $requisitos, EjecutorPruebas $ejecutor): View
    {
        // Pruebas agrupadas como las muestra el portal del SIN.
        $etapas = CasoPrueba::where('fase', CasoPrueba::FASE_PILOTO)
            ->whereNotNull('etapa')
            ->orderBy('etapa')
            ->orderBy('orden')
            ->get()
            ->groupBy('etapa');

        // Ultima ejecucion de cada caso para esta empresa.
        $ultimas = EjecucionPrueba::where('empresa_id', $empresa->id)
            ->get()
            ->groupBy('caso_id')
            ->map(fn ($grupo) => $grupo->sortByDesc('ejecutado_en')->first());

        return view('admin.pruebas.index', [
            'empresa' => $empresa,
            'etapas' => $etapas,
            'correctas' => $ejecutor->correctasPorCaso($empresa),
            // Pruebas esperando un worker: si el numero no baja, no hay ninguno.
            'enCola' => DB::table('jobs')->where('payload', 'like', '%EjecutarCasoPrueba%')->count(),
            'ultimas' => $ultimas,
            'requisitos' => $this->requisitosPrevios($empresa),
            'progreso' => $requisitos->progresoPiloto($empresa),
        ]);
    }

    /**
     * Corre un solo paso. Sirve para reintentar el que fallo sin repetir los
     * anteriores, que es lo habitual mientras se depura el piloto.
     */
    public function ejecutarCaso(Empresa $empresa, CasoPrueba $caso, EjecutorPruebas $ejecutor): RedirectResponse
    {
        if (($bloqueo = $this->bloqueo($empresa, $caso->requiereCertificado())) !== null) {
            return $this->volver($empresa, $bloqueo);
        }

        $ejecucion = $ejecutor->ejecutarCaso($empresa, $caso);

        $resultado = $ejecucion->estado === EjecucionPrueba::ESTADO_EXITOSO ? 'OK' : 'con error';

        return $this->volver($empresa, "Paso {$caso->orden} ({$caso->nombre}): {$resultado}.");
    }

    /**
     * Corre todas las pruebas de una etapa del portal hasta completar sus
     * pruebas esperadas.
     */
    public function ejecutarEtapa(Empresa $empresa, int $etapa, EjecutorPruebas $ejecutor): RedirectResponse
    {
        abort_unless(array_key_exists($etapa, CasoPrueba::ETAPAS), 404);

        $firma = CasoPrueba::where('etapa', $etapa)->get()
            ->contains(fn (CasoPrueba $caso): bool => $caso->requiereCertificado());

        if (($bloqueo = $this->bloqueo($empresa, $firma)) !== null) {
            return $this->volver($empresa, $bloqueo);
        }

        $nombre = CasoPrueba::ETAPAS[$etapa];

        // Etapa grande (la II son 1800 llamadas): no entra en un request, va a
        // la cola. Las chicas, como la I, siguen corriendo en el momento.
        $faltan = CasoPrueba::where('etapa', $etapa)->get()
            ->sum(fn (CasoPrueba $caso): int => $ejecutor->faltantes($empresa, $caso));

        if ($faltan > self::MAXIMO_SINCRONO) {
            $encolados = $ejecutor->encolarEtapa($empresa, $etapa);

            return $this->volver($empresa, "Etapa {$etapa} ({$nombre}): {$encolados} prueba(s) en cola. ".
                'Necesita un worker corriendo (php artisan queue:work). Recarga para ver el avance.');
        }

        $resultado = $ejecutor->ejecutarEtapa($empresa, $etapa);

        $mensaje = match (true) {
            $resultado['ejecutadas'] === 0 => "Etapa {$etapa} ({$nombre}): ya estaba completa, no se envio nada.",
            $resultado['fallidas'] === 0 => "Etapa {$etapa} ({$nombre}): {$resultado['ejecutadas']} prueba(s) enviadas, todas OK.",
            default => "Etapa {$etapa} ({$nombre}): {$resultado['fallidas']} de {$resultado['ejecutadas']} con error. Mira la respuesta de cada prueba.",
        };

        return $this->volver($empresa, $mensaje);
    }

    /**
     * Cierra las operaciones del sistema en el punto de venta de una prueba,
     * para que el SIN acepte emitir un CUIS nuevo (ver EjecutorPruebas).
     */
    public function cerrarOperaciones(Empresa $empresa, CasoPrueba $caso, EjecutorPruebas $ejecutor): RedirectResponse
    {
        abort_unless($caso->tipo === 'solicitudCuis', 404);

        if (($bloqueo = $this->bloqueo($empresa, false)) !== null) {
            return $this->volver($empresa, $bloqueo);
        }

        try {
            $resultado = $ejecutor->cerrarOperacionesDePrueba($empresa, $caso);
        } catch (SiatException $e) {
            return $this->volver($empresa, 'No se pudo cerrar: '.$e->getMessage());
        }

        return $this->volver($empresa, $resultado['cerrado']
            ? "Operaciones cerradas (CUIS {$resultado['cuis']}). Ahora ejecuta otra vez la prueba {$caso->orden}."
            : "El SIN no cerro las operaciones: {$resultado['motivo']}");
    }

    /**
     * Borra el registro LOCAL de ejecuciones de una etapa (o de todas) y los
     * jobs de esas pruebas que sigan en cola, para volver a contar desde cero.
     *
     * No deshace nada ante el SIN —lo que el portal ya conto, queda— ni borra
     * facturas, CUIS o CUFD: son datos reales que otras etapas usan.
     */
    public function limpiar(Request $request, Empresa $empresa): RedirectResponse
    {
        $datos = $request->validate([
            'etapa' => ['nullable', 'integer', Rule::in(array_keys(CasoPrueba::ETAPAS))],
        ]);

        $casos = CasoPrueba::where('fase', CasoPrueba::FASE_PILOTO)
            ->when(isset($datos['etapa']), fn ($q) => $q->where('etapa', $datos['etapa']))
            ->pluck('id');

        $borradas = EjecucionPrueba::where('empresa_id', $empresa->id)->whereIn('caso_id', $casos)->delete();

        // Sin esto, un lote a medias seguiria sumando ejecuciones despues de
        // limpiar. Se deserializa el job en vez de buscar con LIKE en el
        // payload: el escapado de comillas cambia entre MySQL y SQLite.
        $jobs = DB::table('jobs')
            ->where('payload', 'like', '%EjecutarCasoPrueba%')
            ->get(['id', 'payload'])
            ->filter(function ($job) use ($empresa, $casos): bool {
                $comando = unserialize((string) data_get(json_decode($job->payload), 'data.command'));

                return $comando instanceof EjecutarCasoPrueba
                    && $comando->empresaId === $empresa->id
                    && $casos->contains($comando->casoId);
            })
            ->pluck('id');

        DB::table('jobs')->whereIn('id', $jobs)->delete();

        $alcance = isset($datos['etapa']) ? "Etapa {$datos['etapa']}" : 'Todas las etapas';

        return $this->volver($empresa, "{$alcance}: {$borradas} ejecucion(es) borradas y {$jobs->count()} job(s) quitados de la cola.");
    }

    /**
     * Encola lo que le falta a UNA prueba (p. ej. las 50 de un catalogo), para
     * completarla sin lanzar la etapa entera.
     */
    public function completarCaso(Empresa $empresa, CasoPrueba $caso, EjecutorPruebas $ejecutor): RedirectResponse
    {
        abort_if($caso->etapa === null, 404);

        if (($bloqueo = $this->bloqueo($empresa, $caso->requiereCertificado())) !== null) {
            return $this->volver($empresa, $bloqueo);
        }

        $encolados = $ejecutor->encolarCaso($empresa, $caso);

        return $this->volver($empresa, $encolados === 0
            ? "Prueba {$caso->orden}: ya estaba completa."
            : "Prueba {$caso->orden}: {$encolados} ejecucion(es) en cola. Necesita php artisan queue:work.");
    }

    /**
     * Motivo por el que no se puede correr, o null si se puede.
     *
     * Antes el bloqueo era global (token Y certificado para todo) y solo en el
     * boton deshabilitado. Ahora es por lo que la prueba usa de verdad: pedir
     * un CUIS solo necesita el token; el certificado, solo lo que firma.
     */
    private function bloqueo(Empresa $empresa, bool $necesitaCertificado): ?string
    {
        $requisitos = $this->requisitosPrevios($empresa);

        if (! $requisitos['token']) {
            return 'Falta el token delegado de la empresa: cargalo en la ficha antes de correr pruebas.';
        }

        if ($necesitaCertificado && ! $requisitos['certificado']) {
            return 'Esta prueba firma documentos: carga el certificado .p12 antes de correrla.';
        }

        return null;
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
