<?php

namespace App\Services\Factura;

use App\Models\Factura;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Genera el codigo QR de verificacion de una factura.
 *
 * El contenido del QR es la URL publica del SIN donde cualquiera puede
 * verificar la factura con su CUF, numero y NIT emisor.
 *
 * La pagina depende del AMBIENTE de la empresa (config siat.qr.urls): antes
 * apuntaba siempre a produccion y el QR de una factura del piloto llevaba a una
 * consulta donde esa factura no existe.
 */
class GeneradorQr
{
    /** Parametro 't' del QR del SIN: tamano de la representacion grafica. */
    public const FORMATO_ROLLO = 1;

    public const FORMATO_CARTA = 2;

    /**
     * URL que codifica el QR: identifica la factura para su verificacion.
     *
     * @param  int|null  $formato  FORMATO_ROLLO o FORMATO_CARTA; por defecto,
     *                             el configurado (siat.qr.tamanio).
     */
    public function urlVerificacion(Factura $factura, ?int $formato = null): string
    {
        $empresa = $factura->empresa;

        $base = config("siat.qr.urls.{$empresa->codigo_ambiente}")
            ?? config('siat.qr.urls.'.config('siat.codigos.ambiente.produccion'));

        $parametros = http_build_query([
            'nit' => $empresa->nit,
            'cuf' => $factura->cuf,
            'numero' => $factura->numero_factura,
            't' => $formato ?? config('siat.qr.tamanio'),
        ]);

        return $base.'?'.$parametros;
    }

    /**
     * Devuelve el QR como SVG (no necesita imagick, solo GD) para incrustarlo
     * en el PDF o servirlo por la API.
     */
    public function svg(Factura $factura, int $tamanio = 150, ?int $formato = null): string
    {
        return (string) QrCode::format('svg')
            ->size($tamanio)
            ->margin(1)
            ->generate($this->urlVerificacion($factura, $formato));
    }

    /**
     * QR como data URI listo para un atributo src de <img>.
     */
    public function dataUri(Factura $factura, int $tamanio = 150, ?int $formato = null): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svg($factura, $tamanio, $formato));
    }
}
