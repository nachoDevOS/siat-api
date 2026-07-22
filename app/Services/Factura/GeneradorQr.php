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
 * OJO: confirmar el dominio y los nombres de parametros de la URL de consulta
 * contra el manual vigente del SIN antes de produccion (rule 7).
 */
class GeneradorQr
{
    // Base de la URL publica de consulta del SIN.
    private const BASE_CONSULTA = 'https://siat.impuestos.gob.bo/consulta/QR';

    /**
     * URL que codifica el QR: identifica la factura para su verificacion.
     */
    public function urlVerificacion(Factura $factura): string
    {
        $parametros = http_build_query([
            'nit' => $factura->empresa->nit,
            'cuf' => $factura->cuf,
            'numero' => $factura->numero_factura,
            't' => 1, // tipo de factura; verificar contra el manual del SIN
        ]);

        return self::BASE_CONSULTA.'?'.$parametros;
    }

    /**
     * Devuelve el QR como SVG (no necesita imagick, solo GD) para incrustarlo
     * en el PDF o servirlo por la API.
     */
    public function svg(Factura $factura, int $tamanio = 150): string
    {
        return (string) QrCode::format('svg')
            ->size($tamanio)
            ->margin(1)
            ->generate($this->urlVerificacion($factura));
    }

    /**
     * QR como data URI listo para un atributo src de <img>.
     */
    public function dataUri(Factura $factura, int $tamanio = 150): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode($this->svg($factura, $tamanio));
    }
}
