<?php

namespace App\Services\Siat;

use App\Models\PuntoVenta;

/**
 * Servicio FacturacionCodigos: verificar comunicacion, CUIS, CUFD y verificar NIT.
 *
 * VERIFICADO CONTRA EL WSDL DEL PILOTO (2026-08-10). Las siete operaciones que
 * expone son: verificarComunicacion, verificarNit, cuis, cuisMasivo, cufd,
 * cufdMasivo y notificaCertificadoRevocado.
 *
 * OJO: el WSDL no expone 'cafc'. No es un olvido del SIN: el CAFC pertenece a
 * la modalidad computarizada, que este sistema no factura. Se quito el metodo
 * que lo pedia (solo podia fallar con "Function not found").
 *
 * Para volver a listar el contrato:
 *
 *     php artisan siat:inspeccionar-wsdl {empresa} --servicio=codigos --tipos
 */
class ServicioCodigos extends ServicioBase
{
    /**
     * Prueba liviana de que hay comunicacion con el SIAT.
     *
     * El WSDL declara 'struct verificarComunicacion { }': la operacion NO lleva
     * parametros. Antes se le mandaba la cabecera comun y el SIN la ignoraba.
     */
    public function verificarComunicacion(): mixed
    {
        return $this->invocar('codigos', 'verificarComunicacion', []);
    }

    /**
     * Solicita el CUIS de un punto de venta (dura ~1 anio).
     */
    public function solicitarCuis(PuntoVenta $puntoVenta): mixed
    {
        $solicitud = $this->solicitudBase();
        $solicitud['codigoSucursal'] = $puntoVenta->sucursal->codigo_sucursal;
        $solicitud['codigoPuntoVenta'] = $puntoVenta->codigo_punto_venta;

        return $this->invocar('codigos', 'cuis', [
            'SolicitudCuis' => $solicitud,
        ]);
    }

    /**
     * Solicita un CUFD (dura 24 horas). Necesita el CUIS vigente.
     */
    public function solicitarCufd(PuntoVenta $puntoVenta, string $cuis): mixed
    {
        $solicitud = $this->solicitudBase();
        $solicitud['codigoSucursal'] = $puntoVenta->sucursal->codigo_sucursal;
        $solicitud['codigoPuntoVenta'] = $puntoVenta->codigo_punto_venta;
        $solicitud['cuis'] = $cuis;

        return $this->invocar('codigos', 'cufd', [
            'SolicitudCufd' => $solicitud,
        ]);
    }

    /**
     * Verifica que un NIT exista y este activo ante el SIN.
     *
     * El WSDL declara 'solicitudVerificarNit' con codigoSucursal y SIN
     * codigoPuntoVenta. Faltando la sucursal, ext-soap ni siquiera llega a
     * enviar: corta con "object has no 'codigoSucursal' property".
     */
    public function verificarNit(string $nit, string $cuis, int $codigoSucursal = 0): mixed
    {
        $solicitud = $this->solicitudBase();
        $solicitud['codigoSucursal'] = $codigoSucursal;
        $solicitud['cuis'] = $cuis;
        $solicitud['nitParaVerificacion'] = (int) $nit;

        return $this->invocar('codigos', 'verificarNit', [
            'SolicitudVerificarNit' => $solicitud,
        ]);
    }
}
