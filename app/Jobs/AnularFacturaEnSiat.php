<?php

namespace App\Jobs;

use App\Exceptions\SiatException;
use App\Models\Factura;
use App\Models\FacturaAnulada;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\RespuestaSiat;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Support\Facades\Log;

/**
 * Transmite al SIAT la anulacion que el cliente ya registro localmente.
 *
 * La anulacion se guarda primero en nuestra base (respuesta inmediata al
 * sistema de ventas) y este job la confirma contra el SIN. Hasta que el SIN
 * devuelva su codigo de recepcion, la anulacion esta registrada pero no
 * confirmada.
 *
 * Es unico por factura: la tarea programada que retoma las anulaciones
 * colgadas puede despacharlo mientras otro sigue en cola. Dos anulaciones en
 * vuelo serian peligrosas: la segunda vuelve del SIN como "ya anulada", se lee
 * como rechazo y revertiria en local una anulacion que SI se hizo.
 */
#[UniqueFor(3600)]
#[Queue('facturas')]
class AnularFacturaEnSiat implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly int $facturaId) {}

    public function uniqueId(): string
    {
        return (string) $this->facturaId;
    }

    public function handle(FabricaServicios $fabrica): void
    {
        $factura = Factura::with(['empresa', 'puntoVenta.sucursal', 'cufd'])->find($this->facturaId);

        if ($factura === null) {
            return;
        }

        $anulacion = FacturaAnulada::where('factura_id', $factura->id)->first();

        // Sin registro de anulacion no hay nada que transmitir; y si el SIN ya
        // dio su codigo de recepcion o ya la rechazo, este job ya cumplio.
        if ($anulacion === null
            || $anulacion->estado !== FacturaAnulada::ESTADO_PENDIENTE
            || filled($anulacion->codigo_recepcion)) {
            return;
        }

        // El SIN pide el CUFD VIGENTE para anular, no el de la emision (que a
        // esta altura puede estar vencido).
        $cufdVigente = $factura->puntoVenta->cufdVigente() ?? $factura->cufd;
        $cufd = $cufdVigente?->codigo;
        // El CUIS que genero ese CUFD (PuntoVenta::cuisDe).
        $cuis = $factura->puntoVenta->cuisDe($cufdVigente)?->codigo;

        try {
            $respuesta = RespuestaSiat::desde(
                $fabrica->facturacion($factura->empresa)
                    ->anular($factura, (int) $anulacion->motivo, (string) $cufd, (string) $cuis),
            );
        } catch (SiatException $e) {
            // El SIAT no responde. Se reintenta respetando el backoff declarado
            // del job.
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 900);

                return;
            }

            // Agotados los intentos NO se lanza: antes quedaba en failed_jobs y
            // nadie la retomaba, con la factura ANULADA aca y vigente en el SIN.
            // Sigue PENDIENTE y la tarea programada 'reintentar-anulaciones' la
            // vuelve a despachar.
            Log::warning('El SIAT no respondio a la anulacion; queda pendiente para la tarea programada.', [
                'factura_id' => $factura->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // El SIN puede rechazar la anulacion (fuera de plazo, motivo invalido,
        // factura que no conoce) sin lanzar SoapFault. Si eso pasa, la factura
        // SIGUE VIGENTE ante el SIN: dejarla ANULADA en local seria mentirle al
        // cliente, asi que se revierte y se registra el motivo.
        if (! $respuesta->aceptada) {
            match ($this->anuladaEnElSin($factura, $fabrica, (string) $cufd, (string) $cuis)) {
                true => $this->confirmar($factura, $anulacion, null),
                false => $this->revertir($factura, $anulacion, $respuesta),
                // No se pudo saber: queda PENDIENTE y la retoma la tarea
                // programada. Revertir a ciegas podria desanular aca una
                // factura que el SIN ya anulo.
                null => null,
            };

            return;
        }

        $this->confirmar($factura, $anulacion, $respuesta->codigoRecepcion);
    }

    /**
     * Si el SIN ya tiene la factura anulada, segun su verificacion de estado.
     *
     * Un rechazo de la anulacion puede ser falso: si un intento anterior la
     * anulo pero su respuesta se perdio por timeout, el reintento vuelve como
     * "ya anulada" y se leia como rechazo, dejando la factura vigente aca y
     * anulada en el SIN. Antes de revertir se le pregunta al SIN.
     *
     * PENDIENTE DE VERIFICAR: el codigo de estado de una factura anulada no
     * esta contrastado; se reconoce por la descripcion. Si no la reconoce,
     * devuelve false y se revierte como antes.
     *
     * @return bool|null null si el SIN no respondio la verificacion.
     */
    private function anuladaEnElSin(Factura $factura, FabricaServicios $fabrica, string $cufd, string $cuis): ?bool
    {
        try {
            $estado = RespuestaSiat::desde(
                $fabrica->facturacion($factura->empresa)->verificarEstado($factura, $cufd, $cuis),
            );
        } catch (SiatException) {
            return null;
        }

        return (bool) preg_match('/ANULAD|ANULACION CONFIRMADA/', mb_strtoupper((string) $estado->descripcion));
    }

    private function confirmar(Factura $factura, FacturaAnulada $anulacion, ?string $codigoRecepcion): void
    {
        $anulacion->update([
            'codigo_recepcion' => $codigoRecepcion,
            'estado' => FacturaAnulada::ESTADO_CONFIRMADA,
        ]);

        NotificarWebhook::dispatch($factura->id, 'factura.anulada');
    }

    /**
     * Devuelve la factura al estado que tenia antes de pedir la anulacion y
     * deja escrito por que el SIN la rechazo.
     */
    private function revertir(Factura $factura, FacturaAnulada $anulacion, RespuestaSiat $respuesta): void
    {
        $anulacion->update([
            'estado' => FacturaAnulada::ESTADO_RECHAZADA,
            'motivo_rechazo' => $respuesta->motivo(),
        ]);

        $factura->update([
            'estado' => $anulacion->estado_anterior ?? Factura::ESTADO_VALIDADA,
        ]);

        Log::warning('El SIN rechazo la anulacion de la factura.', [
            'factura_id' => $factura->id,
            'cuf' => $factura->cuf,
            'motivo' => $respuesta->motivo(),
        ]);

        NotificarWebhook::dispatch($factura->id, 'factura.anulacion_rechazada');
    }
}
