<?php

namespace App\Services\Factura;

use App\Exceptions\SiatException;
use App\Jobs\AnularFacturaEnSiat;
use App\Jobs\NotificarWebhook;
use App\Models\Catalogo;
use App\Models\Factura;
use App\Models\FacturaAnulada;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\RespuestaSiat;

/**
 * Anula una factura y revierte su anulacion. La usan la API (sistema de
 * ventas) y el panel, para que las dos apliquen las mismas reglas.
 *
 * La anulacion se guarda y la factura queda ANULADA de inmediato; la
 * transmision al SIAT la hace AnularFacturaEnSiat en segundo plano.
 *
 * El SIN permite anular y revertir UNA sola vez: una factura cuya anulacion
 * ya se revirtio no se puede volver a anular.
 */
class AnuladorFactura
{
    public function __construct(private readonly FabricaServicios $fabrica) {}

    /**
     * Estados en los que el SIN ya conoce la factura y por lo tanto acepta su
     * anulacion. Una factura que todavia no viajo se corrige antes de
     * enviarla, no se anula.
     *
     * @var list<string>
     */
    public const ESTADOS_ANULABLES = [
        Factura::ESTADO_ENVIADA,
        Factura::ESTADO_RECIBIDA,
        Factura::ESTADO_VALIDADA,
    ];

    public function esAnulable(Factura $factura): bool
    {
        return in_array($factura->estado, self::ESTADOS_ANULABLES, true)
            && ! $this->yaSeRevirtio($factura);
    }

    /**
     * Solo se revierte una anulacion que el SIN ya confirmo.
     */
    public function esRevertible(Factura $factura): bool
    {
        return $factura->estado === Factura::ESTADO_ANULADA
            && $factura->anulacion?->estado === FacturaAnulada::ESTADO_CONFIRMADA;
    }

    public function yaSeRevirtio(Factura $factura): bool
    {
        return $factura->anulacion?->estado === FacturaAnulada::ESTADO_REVERTIDA;
    }

    /**
     * Motivos de anulacion del catalogo del SIN, por codigo.
     *
     * @return array<string, string>
     */
    public function motivos(): array
    {
        return Catalogo::deTipo('motivos_anulacion')
            ->orderBy('codigo_clasificador')
            ->pluck('descripcion', 'codigo_clasificador')
            ->all();
    }

    /**
     * Registra la anulacion y la encola para el SIAT. Quien llama ya verifico
     * que la factura sea anulable (esAnulable).
     */
    public function anular(Factura $factura, int $motivo): void
    {
        // Se guarda el estado previo: si el SIN rechaza la anulacion, el job la
        // devuelve ahi en vez de dejarla ANULADA de este lado y vigente del otro.
        FacturaAnulada::updateOrCreate(
            ['factura_id' => $factura->id],
            [
                'motivo' => $motivo,
                'anulada_en' => now(),
                'estado' => FacturaAnulada::ESTADO_PENDIENTE,
                'estado_anterior' => $factura->estado,
                'motivo_rechazo' => null,
            ],
        );

        $factura->update(['estado' => Factura::ESTADO_ANULADA]);

        AnularFacturaEnSiat::dispatch($factura->id);
    }

    /**
     * Revierte ante el SIN una anulacion confirmada: la factura vuelve a ser
     * valida. Es sincrono (lo pide una persona desde el panel y espera la
     * respuesta). Quien llama ya verifico que sea revertible (esRevertible).
     *
     * @throws SiatException si el SIN no responde o rechaza la reversion.
     */
    public function revertir(Factura $factura): void
    {
        $factura->loadMissing(['empresa', 'puntoVenta.sucursal', 'cufd', 'anulacion']);

        // Como al anular: el SIN pide el CUFD VIGENTE y el CUIS que lo genero.
        $cufd = $factura->puntoVenta->cufdVigente() ?? $factura->cufd;
        $cuis = $factura->puntoVenta->cuisDe($cufd);

        $respuesta = RespuestaSiat::desde(
            $this->fabrica->facturacion($factura->empresa)
                ->revertirAnulacion($factura, (string) $cufd?->codigo, (string) $cuis?->codigo),
        );

        if (! $respuesta->aceptada) {
            throw new SiatException("El SIN no revirtio la anulacion (estado {$respuesta->codigoEstado}): {$respuesta->motivo()}");
        }

        // La anulacion queda como historial: es la que impide anular de nuevo.
        $factura->anulacion->update(['estado' => FacturaAnulada::ESTADO_REVERTIDA]);
        $factura->update(['estado' => $factura->anulacion->estado_anterior ?? Factura::ESTADO_VALIDADA]);

        NotificarWebhook::dispatch($factura->id, 'factura.anulacion_revertida');
    }
}
