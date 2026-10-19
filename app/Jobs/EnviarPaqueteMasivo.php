<?php

namespace App\Jobs;

use App\Exceptions\SiatException;
use App\Models\Factura;
use App\Models\Paquete;
use App\Services\Contingencia\ArmadorPaquete;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\RespuestaSiat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Queue;

/**
 * Envia un paquete de emision masiva (recepcionMasivaFactura) y deja agendada
 * la consulta de su resultado. El paquete es el mismo TAR de XML firmados que
 * el de contingencia.
 */
#[Queue('facturas')]
class EnviarPaqueteMasivo implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $paqueteId) {}

    public function handle(ArmadorPaquete $armador, FabricaServicios $fabrica): void
    {
        $paquete = Paquete::with(['empresa', 'puntoVenta.sucursal'])->find($this->paqueteId);

        // Solo se manda una vez: un reintento con codigo de recepcion ya
        // guardado duplicaria el paquete del lado del SIN.
        if ($paquete === null || $paquete->estado !== 'PENDIENTE') {
            return;
        }

        $cufd = $paquete->puntoVenta->cufdVigente();

        try {
            $respuesta = RespuestaSiat::desde(
                $fabrica->facturacion($paquete->empresa)->recepcionarMasiva([
                    'codigoSucursal' => $paquete->puntoVenta->sucursal->codigo_sucursal,
                    'codigoPuntoVenta' => $paquete->puntoVenta->codigo_punto_venta,
                    'codigoDocumentoSector' => config('siat.codigos.documento_sector'),
                    'codigoEmision' => Factura::EMISION_MASIVA,
                    'tipoFacturaDocumento' => config('siat.codigos.tipo_factura_documento'),
                    'cufd' => (string) $cufd?->codigo,
                    'cuis' => (string) $paquete->puntoVenta->cuisDe($cufd)?->codigo,
                    'cantidadFacturas' => $paquete->cantidad_facturas,
                ], $armador->armar($paquete)),
            );
        } catch (SiatException $e) {
            // El paquete queda PENDIENTE: el SIAT no respondio y se reintenta.
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 900);

                return;
            }

            throw $e;
        }

        // Rechazado en la recepcion (hash, cantidad, NIT sin autorizacion de
        // emision masiva...): todo el paquete queda observado.
        if (! $respuesta->aceptada || blank($respuesta->codigoRecepcion)) {
            ValidarPaqueteMasivo::observar($paquete, $respuesta->motivo());

            return;
        }

        $paquete->update([
            'estado' => 'ENVIADO',
            'enviado_en' => now(),
            'codigo_recepcion' => $respuesta->codigoRecepcion,
        ]);

        Factura::where('paquete_id', $paquete->id)
            ->where('estado', Factura::ESTADO_MASIVA)
            ->update(['estado' => Factura::ESTADO_ENVIADA, 'enviada_en' => now()]);

        // El SIN procesa el paquete aparte: se le pregunta el resultado.
        ValidarPaqueteMasivo::dispatch($paquete->id)->delay(now()->addSeconds(30));
    }
}
