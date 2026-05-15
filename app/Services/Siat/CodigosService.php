<?php

namespace App\Services\Siat;

use SoapFault;

/**
 * Servicio SOAP para el endpoint FacturacionCodigos del SIN.
 * Responsabilidades: verificar comunicación, obtener CUIS/CUFD, verificar NIT.
 */
class CodigosService extends SiatBaseService
{
    private const WSDL = 'FacturacionCodigos?wsdl';

    /**
     * Verifica que el SIN esté respondiendo.
     * Retorna el objeto de respuesta o SoapFault si no hay conexión.
     */
    public function verificarComunicacion(): mixed
    {
        if (!class_exists('SoapClient')) {
            return (object) ['error' => 'La extensión SOAP no está habilitada en el servidor.'];
        }

        try {
            $cliente = $this->buildClient(self::WSDL);
            return $cliente->verificarComunicacion();
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /**
     * Solicita un CUIS al SIN para la sucursal/PV indicados.
     * El CUIS tiene vigencia anual; se reutiliza mientras no venza.
     *
     * @return mixed  Objeto con RespuestaCuis o SoapFault
     */
    public function cuis(int $codigoSucursal, int $codigoPuntoVenta): mixed
    {
        try {
            $cliente = $this->buildClient(self::WSDL);

            return $cliente->cuis([
                'SolicitudCuis' => $this->baseParams($codigoSucursal, $codigoPuntoVenta),
            ]);
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /**
     * Solicita un CUFD al SIN para la sucursal/PV indicados.
     * Requiere un CUIS vigente. Vigencia típica: diaria.
     *
     * @return mixed  Objeto con RespuestaCufd o SoapFault
     */
    public function cufd(int $codigoSucursal, int $codigoPuntoVenta, string $cuis): mixed
    {
        try {
            $cliente = $this->buildClient(self::WSDL);

            $params = $this->baseParams($codigoSucursal, $codigoPuntoVenta);
            $params['cuis'] = $cuis;

            return $cliente->cufd(['SolicitudCufd' => $params]);
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /**
     * Verifica si un NIT está habilitado en el padrón del SIN.
     * Solo aplica cuando codigoTipoDocumentoIdentidad = 5 (NIT).
     *
     * @return mixed  Objeto con RespuestaVerificarNit o SoapFault
     */
    public function verificarNit(int $codigoSucursal, string $cuis, string|int $nit): mixed
    {
        try {
            $cliente = $this->buildClient(self::WSDL);

            return $cliente->verificarNit([
                'SolicitudVerificarNit' => [
                    'codigoAmbiente'      => config('siat.codigo_ambiente'),
                    'codigoModalidad'     => config('siat.codigo_modalidad'),
                    'codigoSistema'       => config('siat.codigo_sistema'),
                    'codigoSucursal'      => $codigoSucursal,
                    'cuis'                => $cuis,
                    'nit'                 => (int) config('siat.nit'),
                    'nitParaVerificacion' => $nit,
                ],
            ]);
        } catch (SoapFault $e) {
            return $e;
        }
    }
}
