<?php

namespace App\Jobs;

use App\Exceptions\SiatException;
use App\Models\Factura;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\RespuestaSiat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;

/**
 * Consulta al SIAT el estado final de una factura ya enviada y lo refleja
 * (VALIDADA u OBSERVADA), avisando al cliente por webhook.
 */
#[Queue('facturas')]
class VerificarEstadoFactura implements ShouldQueue
{
    use Queueable;

    /*
    |--------------------------------------------------------------------------
    | Codigos de estado del SIN — VERIFICADOS
    |--------------------------------------------------------------------------
    |
    | Contrastados contra un sistema que factura en produccion ante el SIN. El
    | mapeo anterior era peligroso: daba por VALIDADA una factura en 902, que en
    | realidad significa PENDIENTE. Al cliente se le confirmaba un documento que
    | el SIN todavia no habia aceptado.
    |
    | El detalle textual llega en 'codigoDescripcion'; el numero manda.
    |
    */

    /** La factura paso la validacion del SIN. */
    public const CODIGO_VALIDADA = '908';

    /** Tambien se devuelve como validada en algunas respuestas del SIN. */
    public const CODIGO_VALIDADA_ALTERNO = '690';

    /**
     * El SIN todavia no la proceso. NO es un rechazo: hay que volver a
     * preguntar mas tarde, no marcarla observada.
     */
    public const CODIGO_PENDIENTE = '902';

    /**
     * Codigos que confirman que el SIN acepto la factura.
     *
     * @var list<string>
     */
    public const CODIGOS_VALIDADA = [
        self::CODIGO_VALIDADA,
        self::CODIGO_VALIDADA_ALTERNO,
    ];

    /**
     * Descripciones que el SIN devuelve junto al codigo de una factura valida.
     *
     * @var list<string>
     */
    public const DESCRIPCIONES_VALIDADA = ['VALIDADA', 'VALIDA'];

    public int $tries = 5;

    public array $backoff = [60, 120, 300, 600];

    public function __construct(public readonly int $facturaId) {}

    public function handle(FabricaServicios $fabrica): void
    {
        $factura = Factura::with(['empresa', 'puntoVenta.sucursal', 'cufd'])->find($this->facturaId);

        // Tampoco una ANULADA: si se anulo mientras este job esperaba en la
        // cola, el estado que devuelve el SIN la pisaria y la anulacion se
        // perderia de este lado.
        if ($factura === null || in_array($factura->estado, [Factura::ESTADO_VALIDADA, Factura::ESTADO_ANULADA], true)) {
            return;
        }

        $cufd = $factura->cufd?->codigo ?? optional($factura->puntoVenta->cufdVigente())->codigo;

        try {
            $respuesta = $fabrica->facturacion($factura->empresa)
                ->verificarEstado($factura, (string) $cufd, (string) $factura->puntoVenta->cuisDe(
                    $factura->cufd ?? $factura->puntoVenta->cufdVigente(),
                )?->codigo);

            $leida = RespuestaSiat::desde($respuesta);
            $codigoEstado = (string) data_get($respuesta, 'RespuestaServicioFacturacion.codigoEstado');
            $descripcion = mb_strtoupper((string) data_get($respuesta, 'RespuestaServicioFacturacion.codigoDescripcion'));

            $factura->update(['codigo_estado_siat' => $codigoEstado]);

            // Todavia en cola del lado del SIN: no es un rechazo. Se vuelve a
            // preguntar mas tarde y la factura conserva su estado actual.
            if ($codigoEstado === self::CODIGO_PENDIENTE) {
                $this->reintentar();

                return;
            }

            $validada = in_array($codigoEstado, self::CODIGOS_VALIDADA, true)
                || in_array($descripcion, self::DESCRIPCIONES_VALIDADA, true);

            $factura->update([
                'estado' => $validada ? Factura::ESTADO_VALIDADA : Factura::ESTADO_OBSERVADA,
                'validada_en' => $validada ? now() : null,
                // El porque del rechazo, con el codigo de estado si el SIN no
                // adjunto mensajes.
                'motivo_observacion' => $validada ? null : $this->motivo($leida, $codigoEstado),
            ]);

            NotificarWebhook::dispatch(
                $factura->id,
                $validada ? 'factura.validada' : 'factura.observada',
            );
        } catch (SiatException) {
            // Aun sin respuesta: se reintenta por el backoff del job.
            $this->reintentar();
        }
    }

    /**
     * Motivo legible de una observacion: los mensajes del SIN o, si no vino
     * ninguno, el codigo de estado con su descripcion.
     */
    private function motivo(RespuestaSiat $respuesta, string $codigoEstado): string
    {
        if ($respuesta->mensajes !== []) {
            return $respuesta->motivo();
        }

        return trim("[{$codigoEstado}] ".($respuesta->descripcion ?? 'El SIN observo la factura sin detallar el motivo.'));
    }

    /**
     * Reencola respetando el backoff declarado en vez de una espera fija, que
     * lo contradecia.
     *
     * Agotados los intentos NO se lanza la falla: la factura sigue en un estado
     * legitimo (RECIBIDA, esperando al SIN) y la tarea programada de cada 15
     * minutos la vuelve a tomar. Un failed_jobs por cada factura que el SIN
     * todavia no proceso seria solo ruido.
     */
    private function reintentar(): void
    {
        // En el ultimo intento no se reencola: un release ahi hace que el
        // worker lo descarte por MaxAttemptsExceeded y termine en failed_jobs,
        // justo lo que este metodo quiere evitar.
        if ($this->attempts() >= $this->tries) {
            return;
        }

        $this->release($this->backoff[$this->attempts() - 1] ?? 600);
    }
}
