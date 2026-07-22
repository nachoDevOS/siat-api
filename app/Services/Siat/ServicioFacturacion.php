<?php

namespace App\Services\Siat;

use App\Models\Factura;

/**
 * Servicio ServicioFacturacionCompraVenta: recepcion, anulacion, verificacion
 * de estado y envio de paquetes de contingencia.
 *
 * El XML firmado se envia comprimido en GZIP y acompanado de su hash SHA-256,
 * como exige el SIN.
 *
 * OJO: nombres de operaciones y campos documentados por el SIN; verificar
 * contra el WSDL vigente antes de produccion (rule 7).
 */
class ServicioFacturacion extends ServicioBase
{
    /**
     * Envia una factura individual en linea (recepcionFactura).
     */
    public function recepcionarFactura(Factura $factura, string $cufd): mixed
    {
        $xml = (string) $factura->xml_firmado;

        $solicitud = $this->solicitudBase();
        $solicitud['codigoSucursal'] = $factura->puntoVenta->sucursal->codigo_sucursal;
        $solicitud['codigoPuntoVenta'] = $factura->puntoVenta->codigo_punto_venta;
        $solicitud['codigoDocumentoSector'] = $factura->codigo_documento_sector;
        $solicitud['codigoEmision'] = $factura->tipo_emision;
        $solicitud['cufd'] = $cufd;
        $solicitud['tipoFacturaDocumento'] = 1;
        // El SIN recibe el XML comprimido en gzip + su hash para integridad.
        $solicitud['archivo'] = $this->comprimir($xml);
        $solicitud['fechaEnvio'] = now()->format('Y-m-d\TH:i:s.v');
        $solicitud['hashArchivo'] = hash('sha256', $xml);

        return $this->invocar('compra_venta', 'recepcionFactura', [
            'SolicitudServicioRecepcionFactura' => $solicitud,
        ]);
    }

    /**
     * Consulta el estado de una factura por su CUF.
     */
    public function verificarEstado(Factura $factura, string $cufd): mixed
    {
        $solicitud = $this->solicitudBase();
        $solicitud['codigoSucursal'] = $factura->puntoVenta->sucursal->codigo_sucursal;
        $solicitud['codigoPuntoVenta'] = $factura->puntoVenta->codigo_punto_venta;
        $solicitud['codigoDocumentoSector'] = $factura->codigo_documento_sector;
        $solicitud['cufd'] = $cufd;
        $solicitud['cuf'] = $factura->cuf;

        return $this->invocar('compra_venta', 'verificacionEstadoFactura', [
            'SolicitudServicioVerificacionEstadoFactura' => $solicitud,
        ]);
    }

    /**
     * Anula una factura ya validada, dentro del plazo permitido.
     */
    public function anular(Factura $factura, int $motivo, string $cufd): mixed
    {
        $solicitud = $this->solicitudBase();
        $solicitud['codigoSucursal'] = $factura->puntoVenta->sucursal->codigo_sucursal;
        $solicitud['codigoPuntoVenta'] = $factura->puntoVenta->codigo_punto_venta;
        $solicitud['codigoDocumentoSector'] = $factura->codigo_documento_sector;
        $solicitud['cufd'] = $cufd;
        $solicitud['cuf'] = $factura->cuf;
        $solicitud['codigoMotivo'] = $motivo;

        return $this->invocar('compra_venta', 'anulacionFactura', [
            'SolicitudServicioAnulacionFactura' => $solicitud,
        ]);
    }

    /**
     * Envia un paquete de facturas de contingencia (recepcionPaqueteFactura).
     *
     * @param  string  $archivoPaquete  contenido del paquete ya armado.
     */
    public function recepcionarPaquete(array $datosPaquete, string $archivoPaquete): mixed
    {
        $solicitud = array_merge($this->solicitudBase(), $datosPaquete);
        $solicitud['archivo'] = $this->comprimir($archivoPaquete);
        $solicitud['hashArchivo'] = hash('sha256', $archivoPaquete);
        $solicitud['fechaEnvio'] = now()->format('Y-m-d\TH:i:s.v');

        return $this->invocar('compra_venta', 'recepcionPaqueteFactura', [
            'SolicitudServicioRecepcionPaquete' => $solicitud,
        ]);
    }

    /**
     * Confirma el procesamiento de un paquete ya enviado.
     *
     * @param  array<string, mixed>  $datos
     */
    public function validarRecepcionPaquete(array $datos): mixed
    {
        $solicitud = array_merge($this->solicitudBase(), $datos);

        return $this->invocar('compra_venta', 'validacionRecepcionPaquete', [
            'SolicitudServicioValidacionRecepcionPaquete' => $solicitud,
        ]);
    }

    /**
     * Comprime el XML en gzip y lo devuelve en binario (el SoapClient lo
     * codifica en base64 al serializar el tipo base64Binary).
     */
    private function comprimir(string $xml): string
    {
        return gzencode($xml, 9);
    }
}
