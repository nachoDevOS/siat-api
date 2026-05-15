<?php

namespace App\Services\Siat;

use SoapFault;

/**
 * Servicio SOAP para el endpoint ServicioFacturacionCompraVenta del SIN.
 * Responsabilidades: recepción de facturas individuales y paquetes,
 * anulación, reversión, verificación de estado.
 */
class FacturacionService extends SiatBaseService
{
    private const WSDL = 'ServicioFacturacionCompraVenta?wsdl';

    /**
     * Envía una factura individual al SIN (emisión en línea, codigoEmision=1).
     * El archivo debe ser el binario GZ del XML de la factura.
     *
     * @param  string $archivo     Binario del XML comprimido en GZ
     * @param  string $fechaEnvio  Timestamp formato Y-m-d\TH:i:s.v
     * @param  string $hashArchivo SHA256 del binario GZ
     * @param  string $cufd        Código CUFD del día
     * @param  int    $tipoEmision 1=En línea, 2=Fuera de línea
     * @return mixed  Objeto con RespuestaServicioFacturacion o SoapFault
     */
    public function recepcionFactura(
        string $archivo,
        string $fechaEnvio,
        string $hashArchivo,
        string $cufd,
        string $cuis,
        int $codigoSucursal,
        int $codigoPuntoVenta,
        int $tipoEmision = 1
    ): mixed {
        try {
            $cliente = $this->buildClient(self::WSDL);

            $params = $this->baseParams($codigoSucursal, $codigoPuntoVenta);
            $params = array_merge($params, [
                'codigoDocumentoSector' => config('siat.codigo_documento_sector'),
                'codigoEmision'         => $tipoEmision,
                'cufd'                  => $cufd,
                'cuis'                  => $cuis,
                'tipoFacturaDocumento'  => config('siat.tipo_factura_documento'),
                'archivo'               => $archivo,
                'fechaEnvio'            => $fechaEnvio,
                'hashArchivo'           => $hashArchivo,
            ]);

            return $cliente->recepcionFactura([
                'SolicitudServicioRecepcionFactura' => $params,
            ]);
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /**
     * Envía un paquete de facturas de contingencia (codigoEmision=2).
     * El archivo es un .tar.gz con los XMLs de hasta 500 facturas.
     *
     * @param  string      $archivo         Binario del .tar.gz
     * @param  string      $fechaEnvio
     * @param  string      $hashArchivo     SHA256 del binario .tar.gz
     * @param  string      $cufd            CUFD vigente al momento del envío
     * @param  string      $cuis
     * @param  int         $codigoEvento    ID del evento significativo registrado previamente
     * @param  int         $cantidadFacturas Cantidad de facturas en el paquete (máx 500)
     * @param  string|null $cafc            CAFC para eventos de contingencia (5,6,7), null para otros
     * @return mixed
     */
    public function recepcionPaquete(
        string $archivo,
        string $fechaEnvio,
        string $hashArchivo,
        string $cufd,
        string $cuis,
        int $codigoSucursal,
        int $codigoPuntoVenta,
        int $codigoEvento,
        int $cantidadFacturas,
        ?string $cafc = null
    ): mixed {
        try {
            $cliente = $this->buildClient(self::WSDL, 'paquete');

            $params = $this->baseParams($codigoSucursal, $codigoPuntoVenta);
            $params = array_merge($params, [
                'codigoDocumentoSector' => config('siat.codigo_documento_sector'),
                'codigoEmision'         => 2, // siempre 2 para paquetes de contingencia
                'cufd'                  => $cufd,
                'cuis'                  => $cuis,
                'tipoFacturaDocumento'  => config('siat.tipo_factura_documento'),
                'archivo'               => $archivo,
                'fechaEnvio'            => $fechaEnvio,
                'hashArchivo'           => $hashArchivo,
                'cafc'                  => $cafc,
                'cantidadFacturas'      => $cantidadFacturas,
                'codigoEvento'          => $codigoEvento,
            ]);

            return $cliente->recepcionPaqueteFactura([
                'SolicitudServicioRecepcionPaquete' => $params,
            ]);
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /**
     * Consulta si un paquete enviado fue procesado por el SIN.
     * Se llama después de recepcionPaquete para confirmar el estado final.
     *
     * @return mixed  Objeto con RespuestaServicioFacturacion o SoapFault
     */
    public function validacionPaquete(
        string $codigoRecepcion,
        string $cufd,
        string $cuis,
        int $codigoSucursal,
        int $codigoPuntoVenta
    ): mixed {
        try {
            $cliente = $this->buildClient(self::WSDL);

            $params = $this->baseParams($codigoSucursal, $codigoPuntoVenta);
            $params = array_merge($params, [
                'codigoDocumentoSector' => config('siat.codigo_documento_sector'),
                'codigoEmision'         => 2,
                'cufd'                  => $cufd,
                'cuis'                  => $cuis,
                'tipoFacturaDocumento'  => config('siat.tipo_factura_documento'),
                'codigoRecepcion'       => $codigoRecepcion,
            ]);

            return $cliente->validacionRecepcionPaqueteFactura([
                'SolicitudServicioValidacionRecepcionPaquete' => $params,
            ]);
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /**
     * Anula una factura en el SIN.
     * Requiere el CUF y el CUFD con el que se emitió la factura (no el CUFD vigente actual).
     *
     * @param  int $codigoMotivo  Código de motivo de anulación (de siat_motivos_anulacion)
     * @return mixed
     */
    public function anulacionFactura(
        string $cufd,
        string $cuf,
        string $cuis,
        int $codigoSucursal,
        int $codigoPuntoVenta,
        int $codigoMotivo
    ): mixed {
        try {
            $cliente = $this->buildClient(self::WSDL);

            $params = $this->baseParams($codigoSucursal, $codigoPuntoVenta);
            $params = array_merge($params, [
                'codigoDocumentoSector' => config('siat.codigo_documento_sector'),
                'codigoEmision'         => 1, // anulación siempre es codigoEmision=1
                'cufd'                  => $cufd,
                'cuis'                  => $cuis,
                'tipoFacturaDocumento'  => config('siat.tipo_factura_documento'),
                'codigoMotivo'          => $codigoMotivo,
                'cuf'                   => $cuf,
            ]);

            return $cliente->anulacionFactura([
                'SolicitudServicioAnulacionFactura' => $params,
            ]);
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /**
     * Revierte la anulación de una factura.
     * Solo se puede revertir una vez; después el campo reversed=true bloquea nuevos intentos.
     *
     * @return mixed
     */
    public function reversionAnulacion(
        string $cufd,
        string $cuf,
        string $cuis,
        int $codigoSucursal,
        int $codigoPuntoVenta
    ): mixed {
        try {
            $cliente = $this->buildClient(self::WSDL);

            $params = $this->baseParams($codigoSucursal, $codigoPuntoVenta);
            $params = array_merge($params, [
                'codigoDocumentoSector' => config('siat.codigo_documento_sector'),
                'codigoEmision'         => 1,
                'cufd'                  => $cufd,
                'cuis'                  => $cuis,
                'tipoFacturaDocumento'  => config('siat.tipo_factura_documento'),
                'cuf'                   => $cuf,
            ]);

            return $cliente->reversionAnulacionFactura([
                'SolicitudServicioReversionAnulacionFactura' => $params,
            ]);
        } catch (SoapFault $e) {
            return $e;
        }
    }

    /**
     * Consulta el estado de una factura en el SIN por su CUF.
     * Usado para reconciliar facturas locales en estado 902 con el SIN.
     *
     * @param  string   $cufd           CUFD con el que se emitió (o el vigente si no se conoce)
     * @param  string   $cuf            CUF de la factura a verificar
     * @param  int|null $codigoEmision  1=Online, 2=Offline. Null usa el default del config (1).
     * @return mixed
     */
    public function verificacionEstado(
        string $cufd,
        string $cuf,
        string $cuis,
        int $codigoSucursal,
        int $codigoPuntoVenta,
        ?int $codigoEmision = null
    ): mixed {
        try {
            $cliente = $this->buildClient(self::WSDL);

            $params = $this->baseParams($codigoSucursal, $codigoPuntoVenta);
            $params = array_merge($params, [
                'codigoDocumentoSector' => config('siat.codigo_documento_sector'),
                'codigoEmision'         => $codigoEmision ?? 1,
                'cufd'                  => $cufd,
                'cuis'                  => $cuis,
                'tipoFacturaDocumento'  => config('siat.tipo_factura_documento'),
                'cuf'                   => $cuf,
            ]);

            return $cliente->verificacionEstadoFactura([
                'SolicitudServicioVerificaEstadoFactura' => $params,
            ]);
        } catch (SoapFault $e) {
            return $e;
        }
    }
}
