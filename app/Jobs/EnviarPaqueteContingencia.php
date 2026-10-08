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
use Illuminate\Support\Facades\Log;

/**
 * Envia un paquete de facturas de contingencia al SIAT (recepcionPaqueteFactura)
 * y marca las facturas como enviadas.
 */
class EnviarPaqueteContingencia implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $paqueteId) {}

    public function handle(ArmadorPaquete $armador, FabricaServicios $fabrica): void
    {
        $paquete = Paquete::with(['empresa', 'puntoVenta.sucursal', 'evento'])->find($this->paqueteId);

        if ($paquete === null || $paquete->estado === 'ENVIADO') {
            return;
        }

        $tar = $armador->armar($paquete);

        // El try cubre SOLO la llamada: lo que se reintenta es un SIAT que no
        // responde, nada mas.
        try {
            // Codigos del SIN, no ids internos de nuestras tablas.
            //
            // Todos los campos de solicitudRecepcionPaquete del WSDL. Antes solo
            // viajaban sucursal y punto de venta y ext-soap cortaba sin enviar.
            $respuesta = RespuestaSiat::desde(
                $fabrica->facturacion($paquete->empresa)->recepcionarPaquete([
                    'codigoSucursal' => $paquete->puntoVenta->sucursal->codigo_sucursal,
                    'codigoPuntoVenta' => $paquete->puntoVenta->codigo_punto_venta,
                    'codigoDocumentoSector' => config('siat.codigos.documento_sector'),
                    'codigoEmision' => Factura::EMISION_CONTINGENCIA,
                    'tipoFacturaDocumento' => config('siat.codigos.tipo_factura_documento'),
                    'cufd' => (string) $paquete->puntoVenta->cufdVigente()?->codigo,
                    'cuis' => (string) $paquete->puntoVenta->cuisDe($paquete->puntoVenta->cufdVigente())?->codigo,
                    // El CAFC es de la modalidad computarizada: aca no aplica.
                    'cafc' => null,
                    'cantidadFacturas' => $paquete->cantidad_facturas,
                    // El codigo con el que el SIN registro el evento: es lo que
                    // justifica que estas facturas se emitieran fuera de linea.
                    'codigoEvento' => $paquete->evento?->codigo_recepcion,
                ], $tar),
            );
        } catch (SiatException $e) {
            // El SIAT no respondio. Se respeta el backoff declarado del job en
            // vez de un release fijo, que lo contradecia.
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff[$this->attempts() - 1] ?? 900);

                return;
            }

            // Agotados los reintentos, el paquete sigue en PENDIENTE y sus
            // facturas en contingencia: siguen siendo validas. La falla se
            // propaga para que quede en failed_jobs y se pueda reenviar.
            throw $e;
        }

        // Rechazo del SIN sin SoapFault. Va FUERA del try a proposito: adentro,
        // el throw caia en el catch de arriba y el mismo lote rechazado se
        // reenviaba tres veces, justo lo contrario de lo que decia el comentario.
        //
        // Un rechazo es del contenido del paquete (hash, firma, XML fuera de
        // orden): reintentarlo no lo arregla. fail() lo manda directo a
        // failed_jobs con su motivo, sin reintentos.
        if (! $respuesta->aceptada) {
            Log::warning('El SIN rechazo el paquete de contingencia.', [
                'paquete_id' => $paquete->id,
                'motivo' => $respuesta->motivo(),
            ]);

            // El paquete queda PENDIENTE y sus facturas en contingencia: siguen
            // siendo validas, solo falta corregir el paquete y reenviarlo.
            $this->fail(new SiatException("El SIN rechazo el paquete: {$respuesta->motivo()}"));

            return;
        }

        $paquete->update([
            'estado' => 'ENVIADO',
            'enviado_en' => now(),
            'codigo_recepcion' => $respuesta->codigoRecepcion,
        ]);

        // Solo las facturas de ESTE paquete pasan de contingencia a enviadas.
        Factura::where('paquete_id', $paquete->id)
            ->where('estado', Factura::ESTADO_CONTINGENCIA)
            ->update(['estado' => Factura::ESTADO_ENVIADA, 'enviada_en' => now()]);
    }
}
