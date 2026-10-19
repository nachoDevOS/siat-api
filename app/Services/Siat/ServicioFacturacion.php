<?php

namespace App\Services\Siat;

use App\Models\Factura;

/**
 * Servicio ServicioFacturacionCompraVenta: recepcion, anulacion, verificacion
 * de estado y envio de paquetes de contingencia.
 *
 * Las notas de credito-debito van por ServicioFacturacionDocumentoAjuste, con
 * las mismas cuatro operaciones individuales pero otros nombres. Cada metodo
 * mira el documento y elige servicio, operacion y envoltorio (ver
 * operacion()), asi los jobs de envio, verificacion y anulacion sirven igual
 * para facturas y notas.
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
        $solicitud = $this->cabeceraDeFactura($factura, $cufd, $cuis);
        $solicitud['fechaEnvio'] = now()->format('Y-m-d\TH:i:s.v');

        // El SIN recibe el XML comprimido en gzip, y el hash es EL DEL GZIP,
        // no el del XML original. Verificado contra un sistema en produccion:
        // con el hash del XML plano el SIN rechaza el envio por integridad.
        [$solicitud['archivo'], $solicitud['hashArchivo']] = $this->comprimirYHashear(
            (string) $factura->xml_firmado,
        );

        return $this->invocarPara($factura, 'recepcion', $solicitud);
    }

    /**
     * Consulta el estado de una factura por su CUF.
     */
    public function verificarEstado(Factura $factura, string $cufd, string $cuis): mixed
    {
        $solicitud = $this->cabeceraDeFactura($factura, $cufd, $cuis);
        $solicitud['cuf'] = $factura->cuf;

        return $this->invocarPara($factura, 'verificacion', $solicitud);
    }

    /**
     * Anula una factura ya validada, dentro del plazo permitido.
     *
     * @param  string  $cufd  CUFD VIGENTE del punto de venta (el portal pide "su
     *                        CUFD valido"), no el que se uso al emitir.
     */
    public function anular(Factura $factura, int $motivo, string $cufd, string $cuis): mixed
    {
        $solicitud = $this->cabeceraDeFactura($factura, $cufd, $cuis);
        $solicitud['cuf'] = $factura->cuf;
        $solicitud['codigoMotivo'] = $motivo;

        return $this->invocarPara($factura, 'anulacion', $solicitud);
    }

    /**
     * Revierte la anulacion de una factura: vuelve a quedar valida ante el SIN.
     *
     * VERIFICADO CONTRA EL WSDL DEL PILOTO (2026-10-08): struct
     * solicitudReversionAnulacion = la base de recepcion + cuf. No lleva motivo,
     * aunque el portal lo liste entre los parametros de la prueba.
     */
    public function revertirAnulacion(Factura $factura, string $cufd, string $cuis): mixed
    {
        $solicitud = $this->cabeceraDeFactura($factura, $cufd, $cuis);
        $solicitud['cuf'] = $factura->cuf;

        return $this->invocarPara($factura, 'reversion', $solicitud);
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
     * Envia un paquete de emision masiva (recepcionMasivaFactura).
     *
     * VERIFICADO CONTRA EL WSDL DEL PILOTO /sfd (2026-10-18): struct
     * solicitudRecepcionMasiva = solicitudRecepcionFactura + cantidadFacturas,
     * envoltorio SolicitudServicioRecepcionMasiva.
     *
     * @param  array<string, mixed>  $datosPaquete
     */
    public function recepcionarMasiva(array $datosPaquete, string $archivoPaquete): mixed
    {
        $solicitud = array_merge($this->solicitudBase(), $datosPaquete);
        $solicitud['fechaEnvio'] = now()->format('Y-m-d\TH:i:s.v');

        [$solicitud['archivo'], $solicitud['hashArchivo']] = $this->comprimirYHashear($archivoPaquete);

        return $this->invocar('compra_venta', 'recepcionMasivaFactura', [
            'SolicitudServicioRecepcionMasiva' => $solicitud,
        ]);
    }

    /**
     * Resultado de un paquete de emision masiva ya recibido
     * (validacionRecepcionMasivaFactura, struct solicitudValidacionRecepcion =
     * base + codigoRecepcion). VERIFICADO contra el WSDL del piloto.
     *
     * @param  array<string, mixed>  $datos
     */
    public function validarRecepcionMasiva(array $datos): mixed
    {
        return $this->invocar('compra_venta', 'validacionRecepcionMasivaFactura', [
            'SolicitudServicioValidacionRecepcionMasiva' => array_merge($this->solicitudBase(), $datos),
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

        // El WSDL del piloto la llama validacionRecepcionPaqueteFactura (se
        // listo con siat:inspeccionar-wsdl el 2026-10-07): con el nombre de
        // antes ext-soap cortaba con "Function not found".
        return $this->invocar('compra_venta', 'validacionRecepcionPaqueteFactura', [
            'SolicitudServicioValidacionRecepcionPaquete' => $solicitud,
        ]);
    }

    /**
     * Servicio, operacion y envoltorio SOAP de cada operacion individual.
     *
     * VERIFICADOS contra los WSDL del piloto /sfd: compra-venta (2026-10-07)
     * y documento de ajuste (2026-10-18). El de ajuste hereda la misma struct
     * base 'solicitudRecepcion' y responde RespuestaServicioFacturacion, asi
     * que solo cambian los nombres.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const OPERACIONES = [
        'recepcion' => ['recepcionFactura', 'SolicitudServicioRecepcionFactura'],
        'verificacion' => ['verificacionEstadoFactura', 'SolicitudServicioVerificacionEstadoFactura'],
        'anulacion' => ['anulacionFactura', 'SolicitudServicioAnulacionFactura'],
        'reversion' => ['reversionAnulacionFactura', 'SolicitudServicioReversionAnulacionFactura'],
    ];

    private const OPERACIONES_AJUSTE = [
        'recepcion' => ['recepcionDocumentoAjuste', 'SolicitudServicioRecepcionDocumentoAjuste'],
        'verificacion' => ['verificacionEstadoDocumentoAjuste', 'SolicitudServicioVerificacionEstadoDocumentoAjuste'],
        'anulacion' => ['anulacionDocumentoAjuste', 'SolicitudServicioAnulacionDocumentoAjuste'],
        'reversion' => ['reversionAnulacionDocumentoAjuste', 'SolicitudServicioReversionAnulacionDocumentoAjuste'],
    ];

    /**
     * Invoca la operacion que corresponde al documento: una factura va a
     * compra-venta y una nota de credito-debito al servicio de ajuste.
     *
     * @param  'recepcion'|'verificacion'|'anulacion'|'reversion'  $operacion
     * @param  array<string, mixed>  $solicitud
     */
    private function invocarPara(Factura $factura, string $operacion, array $solicitud): mixed
    {
        [$servicio, $operaciones] = $factura->esNotaCreditoDebito()
            ? ['documento_ajuste', self::OPERACIONES_AJUSTE]
            : ['compra_venta', self::OPERACIONES];

        [$nombre, $envoltorio] = $operaciones[$operacion];

        return $this->invocar($servicio, $nombre, [$envoltorio => $solicitud]);
    }

    /**
     * Campos de la struct base 'solicitudRecepcion' del WSDL, que heredan la
     * recepcion, la anulacion y la verificacion de estado.
     *
     * VERIFICADO CONTRA EL WSDL DEL PILOTO (2026-10-07). Antes la anulacion y la
     * verificacion no mandaban codigoEmision, cuis ni tipoFacturaDocumento, y
     * ext-soap corta sin enviar cuando falta un campo de la struct: ninguna de
     * las dos podia llegar al SIN.
     *
     * @return array<string, mixed>
     */
    private function cabeceraDeFactura(Factura $factura, string $cufd, string $cuis): array
    {
        $solicitud = $this->solicitudBase();
        $solicitud['codigoSucursal'] = $factura->puntoVenta->sucursal->codigo_sucursal;
        $solicitud['codigoPuntoVenta'] = $factura->puntoVenta->codigo_punto_venta;
        $solicitud['codigoDocumentoSector'] = $factura->codigo_documento_sector;
        $solicitud['codigoEmision'] = $factura->tipo_emision;
        $solicitud['tipoFacturaDocumento'] = $factura->tipoFacturaDocumento();
        $solicitud['cufd'] = $cufd;
        $solicitud['cuis'] = $cuis;

        return $solicitud;
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
