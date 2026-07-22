<?php

namespace App\Services\Pruebas;

use App\Exceptions\SiatException;
use App\Models\CasoPrueba;
use App\Models\EjecucionPrueba;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use App\Services\Siat\ServicioCodigos;
use App\Services\Siat\ServicioSincronizacion;
use App\Services\Siat\SiatClient;
use Throwable;

/**
 * Corre los casos de prueba del piloto en orden (seccion 12.2).
 *
 * Cada paso guarda su respuesta cruda y el tiempo. Si uno falla, la secuencia
 * se detiene ahi y puede reintentarse desde ese punto sin repetir los previos.
 */
class EjecutorPruebas
{
    /**
     * Ejecuta la secuencia completa de una fase para una empresa.
     * Se detiene en el primer caso obligatorio que falle.
     *
     * @return array{ejecutados: int, fallo: ?string}
     */
    public function ejecutarSecuencia(Empresa $empresa, int $fase): array
    {
        $casos = CasoPrueba::where('fase', $fase)->orderBy('orden')->get();
        $ejecutados = 0;

        foreach ($casos as $caso) {
            $ejecucion = $this->ejecutarCaso($empresa, $caso);
            $ejecutados++;

            if ($ejecucion->estado === EjecucionPrueba::ESTADO_FALLIDO && $caso->obligatorio) {
                return ['ejecutados' => $ejecutados, 'fallo' => $caso->nombre];
            }
        }

        return ['ejecutados' => $ejecutados, 'fallo' => null];
    }

    /**
     * Ejecuta un caso concreto y registra su ejecucion.
     */
    public function ejecutarCaso(Empresa $empresa, CasoPrueba $caso): EjecucionPrueba
    {
        $inicio = microtime(true);

        try {
            $respuesta = $this->invocarOperacion($empresa, $caso);
            $estado = EjecucionPrueba::ESTADO_EXITOSO;
            $datos = $this->normalizar($respuesta);
        } catch (SiatException|Throwable $e) {
            $estado = EjecucionPrueba::ESTADO_FALLIDO;
            $datos = ['error' => $e->getMessage()];
        }

        return EjecucionPrueba::create([
            'empresa_id' => $empresa->id,
            'caso_id' => $caso->id,
            'estado' => $estado,
            'respuesta' => $datos,
            'duracion_ms' => (int) ((microtime(true) - $inicio) * 1000),
            'ejecutado_en' => now(),
        ]);
    }

    /**
     * Mapea el tipo del caso a la operacion SIAT que le corresponde.
     * Los tipos aun no cableados quedan marcados como pendientes de implementar.
     */
    private function invocarOperacion(Empresa $empresa, CasoPrueba $caso): mixed
    {
        $cliente = new SiatClient($empresa);

        return match ($caso->tipo) {
            'verificarComunicacion' => (new ServicioCodigos($empresa, $cliente))->verificarComunicacion(),
            'fechaHora' => (new ServicioSincronizacion($empresa, $cliente))->fechaHora(),
            'cuis' => (new ServicioCodigos($empresa, $cliente))->solicitarCuis($this->primerPuntoVenta($empresa)),
            default => throw new SiatException("Caso '{$caso->tipo}' aun no implementado en el ejecutor."),
        };
    }

    private function primerPuntoVenta(Empresa $empresa): PuntoVenta
    {
        return PuntoVenta::query()
            ->whereHas('sucursal', fn ($q) => $q->where('empresa_id', $empresa->id))
            ->firstOrFail();
    }

    /**
     * Convierte la respuesta SOAP (objeto) en un arreglo serializable a JSON.
     */
    private function normalizar(mixed $respuesta): array
    {
        return json_decode(json_encode($respuesta) ?: '{}', true) ?? [];
    }
}
