<?php

namespace App\Services\Siat;

use SoapClient;
use SoapFault;

/**
 * Clase base para todos los servicios SOAP del SIAT.
 *
 * Centraliza:
 * - Construcción del cliente SOAP con apikey y timeout.
 * - Resolución del endpoint según ambiente (piloto/producción).
 * - Manejo de SoapFault: los métodos devuelven el objeto SoapFault en lugar de lanzar excepción,
 *   para que el llamador decida cómo manejarlo (igual que en el proyecto ventas).
 */
abstract class SiatBaseService
{
    /**
     * Retorna la URL base del SIN según el ambiente configurado.
     * codigoAmbiente 1 = Producción, 2 = Piloto.
     */
    protected function baseUrl(): string
    {
        $ambiente = config('siat.codigo_ambiente', 2);
        $endpoints = config('siat.endpoints');

        return $ambiente === 1 ? $endpoints['produccion'] : $endpoints['piloto'];
    }

    /**
     * Construye un SoapClient con el header apikey y el timeout correspondiente.
     *
     * @param string $wsdlSuffix  Parte del WSDL después de la base URL, ej: "FacturacionCodigos?wsdl"
     * @param string $timeoutKey  Clave del config siat.timeout (default|paquete)
     */
    protected function buildClient(string $wsdlSuffix, string $timeoutKey = 'default'): SoapClient
    {
        $wsdl    = $this->baseUrl() . $wsdlSuffix;
        $timeout = config("siat.timeout.{$timeoutKey}", 5);
        $token   = config('siat.token');

        $contexto = stream_context_create([
            'http' => [
                'header'  => "apikey: TokenApi {$token}",
                'timeout' => $timeout,
            ],
        ]);

        return new SoapClient($wsdl, [
            'stream_context' => $contexto,
            'cache_wsdl'     => WSDL_CACHE_NONE,
            'compression'    => SOAP_COMPRESSION_ACCEPT | SOAP_COMPRESSION_GZIP | SOAP_COMPRESSION_DEFLATE,
        ]);
    }

    /**
     * Parámetros comunes que van en casi todas las solicitudes SIAT.
     */
    protected function baseParams(int $codigoSucursal, int $codigoPuntoVenta): array
    {
        return [
            'codigoAmbiente'        => config('siat.codigo_ambiente'),
            'codigoModalidad'       => config('siat.codigo_modalidad'),
            'codigoPuntoVenta'      => $codigoPuntoVenta,
            'codigoSistema'         => config('siat.codigo_sistema'),
            'codigoSucursal'        => $codigoSucursal,
            'nit'                   => (int) config('siat.nit'),
        ];
    }
}
