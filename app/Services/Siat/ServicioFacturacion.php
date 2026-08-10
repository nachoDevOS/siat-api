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
 * PENDIENTE DE VERIFICAR CONTRA EL WSDL VIGENTE: los nombres de operacion
 * ('recepcionFactura', 'anulacionFactura', ...) y los de cada campo salen de la
 * documentacion del SIN, no del contrato real. Para contrastarlos:
 *
 *     php artisan siat:inspeccionar-wsdl {empresa} --servicio=compra_venta --tipos
 *
 * Ese comando solo reporta lo que expone el WSDL; no cambia nada.
 */
class ServicioFacturacion extends ServicioBase
{
    /**
     * Envia una factura individual en linea (recepcionFactura).
     *
     * @param  string  $cuis  CUIS vigente del punto de venta: el SIN lo exige
     *                        tambien en esta operacion, no solo al pedir codigos.
     */
    public function recepcionarFactura(Factura $factura, string $cufd, string $cuis): mixed
    {
        $solicitud = $this->solicitudBase();
        $solicitud['codigoSucursal'] = $factura->puntoVenta->sucursal->codigo_sucursal;
        $solicitud['codigoPuntoVenta'] = $factura->puntoVenta->codigo_punto_venta;
        $solicitud['codigoDocumentoSector'] = $factura->codigo_documento_sector;
        $solicitud['codigoEmision'] = $factura->tipo_emision;
        $solicitud['cufd'] = $cufd;
        $solicitud['cuis'] = $cuis;
        $solicitud['tipoFacturaDocumento'] = config('siat.codigos.tipo_factura_documento');
        $solicitud['fechaEnvio'] = now()->format('Y-m-d\TH:i:s.v');

        // El SIN recibe el XML comprimido en gzip, y el hash es EL DEL GZIP,
        // no el del XML original. Verificado contra un sistema en produccion:
        // con el hash del XML plano el SIN rechaza el envio por integridad.
        [$solicitud['archivo'], $solicitud['hashArchivo']] = $this->comprimirYHashear(
            (string) $factura->xml_firmado,
        );

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
        $solicitud['fechaEnvio'] = now()->format('Y-m-d\TH:i:s.v');

        [$solicitud['archivo'], $solicitud['hashArchivo']] = $this->comprimirYHashear($archivoPaquete);

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
     * Comprime el XML en gzip y calcula el hash SHA-256 del RESULTADO
     * comprimido, que es lo que el SIN compara del otro lado.
     *
     * El binario se devuelve tal cual: el SoapClient lo codifica en base64 al
     * serializar el tipo base64Binary del WSDL.
     *
     * @return array{0: string, 1: string} [gzip, hash del gzip]
     */
    private function comprimirYHashear(string $xml): array
    {
        $comprimido = gzencode($xml, 9);

        return [$comprimido, hash('sha256', $comprimido)];
    }
}
