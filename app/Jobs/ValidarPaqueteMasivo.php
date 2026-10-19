<?php

namespace App\Jobs;

use App\Exceptions\SiatException;
use App\Models\Factura;
use App\Models\Paquete;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\RespuestaSiat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;

/**
 * Pregunta al SIN el resultado de un paquete de emision masiva ya recibido
 * (validacionRecepcionMasivaFactura) y lo refleja en sus facturas, con un
 * webhook por factura como en la emision en linea.
 *
 * PENDIENTE DE VERIFICAR EN EL PILOTO: si el SIN observa solo algunas facturas
 * del paquete, como las identifica. Hoy un paquete observado marca OBSERVADAS
 * a todas sus facturas, con el motivo del SIN.
 */
#[Queue('facturas')]
class ValidarPaqueteMasivo implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** @var list<int> */
    public array $backoff = [60, 120, 300, 600, 1200];

    public function __construct(public readonly int $paqueteId) {}

    public function handle(FabricaServicios $fabrica): void
    {
        $paquete = Paquete::with(['empresa', 'puntoVenta.sucursal'])->find($this->paqueteId);

        if ($paquete === null || $paquete->estado !== 'ENVIADO') {
            return;
        }

        $cufd = $paquete->puntoVenta->cufdVigente();

        try {
            $respuesta = RespuestaSiat::desde($fabrica->facturacion($paquete->empresa)->validarRecepcionMasiva([
                'codigoSucursal' => $paquete->puntoVenta->sucursal->codigo_sucursal,
                'codigoPuntoVenta' => $paquete->puntoVenta->codigo_punto_venta,
                'codigoDocumentoSector' => config('siat.codigos.documento_sector'),
                'codigoEmision' => Factura::EMISION_MASIVA,
                'tipoFacturaDocumento' => config('siat.codigos.tipo_factura_documento'),
                'cufd' => (string) $cufd?->codigo,
                'cuis' => (string) $paquete->puntoVenta->cuisDe($cufd)?->codigo,
                'codigoRecepcion' => $paquete->codigo_recepcion,
            ]));
        } catch (SiatException) {
            $this->reintentar();

            return;
        }

        // Todavia procesando, o falla interna del SIN: se vuelve a preguntar.
        if (VerificarEstadoFactura::esTransitorio($respuesta->codigoEstado, $respuesta->descripcion)) {
            $this->reintentar();

            return;
        }

        if (in_array($respuesta->codigoEstado, VerificarEstadoFactura::CODIGOS_VALIDADA, true)) {
            $paquete->update(['estado' => 'VALIDADO']);

            $ids = Factura::where('paquete_id', $paquete->id)->where('estado', Factura::ESTADO_ENVIADA)->pluck('id');
            Factura::whereIn('id', $ids)->update([
                'estado' => Factura::ESTADO_VALIDADA,
                'validada_en' => now(),
                'codigo_recepcion' => $paquete->codigo_recepcion,
                'codigo_estado_siat' => $respuesta->codigoEstado,
            ]);

            $ids->each(fn (int $id) => NotificarWebhook::dispatch($id, 'factura.validada'));

            return;
        }

        self::observar($paquete, $respuesta->motivo(), $respuesta->codigoEstado);
    }

    /**
     * Paquete observado por el SIN: todas sus facturas quedan OBSERVADAS con
     * el motivo y se avisa por cada una.
     */
    public static function observar(Paquete $paquete, string $motivo, ?string $codigoEstado = null): void
    {
        $paquete->update(['estado' => 'OBSERVADO', 'motivo_observacion' => $motivo]);

        $ids = Factura::where('paquete_id', $paquete->id)
            ->whereIn('estado', [Factura::ESTADO_MASIVA, Factura::ESTADO_ENVIADA])
            ->pluck('id');

        Factura::whereIn('id', $ids)->update([
            'estado' => Factura::ESTADO_OBSERVADA,
            'motivo_observacion' => $motivo,
            'codigo_estado_siat' => $codigoEstado,
        ]);

        $ids->each(fn (int $id) => NotificarWebhook::dispatch($id, 'factura.observada'));
    }

    /**
     * Agotados los intentos no se lanza: el paquete sigue ENVIADO y la tarea
     * programada 'validar-paquetes-masivos' lo vuelve a tomar.
     */
    private function reintentar(): void
    {
        if ($this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 1200);
        }
    }
}
