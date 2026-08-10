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
 * OJO: **no hay operacion 'cafc' en este servicio.** Ver solicitarCafc() abajo.
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
     * Solicita un CAFC (reserva para contingencia). Necesita el CUIS vigente.
     *
     * ATENCION: el WSDL del piloto NO expone una operacion 'cafc' en este
     * servicio (2026-08-10). Esta llamada falla con "Function not found".
     * Queda tal cual hasta ubicar donde vive el CAFC en el contrato real —
     * probablemente en FacturacionOperaciones, o no aplica a la modalidad
     * electronica en linea. El CAFC solo hace falta para contingencia, asi que
     * no bloquea la emision normal.
     */
    public function solicitarCafc(PuntoVenta $puntoVenta, string $cuis): mixed
    {
        $solicitud = $this->solicitudBase();
        $solicitud['codigoSucursal'] = $puntoVenta->sucursal->codigo_sucursal;
        $solicitud['codigoPuntoVenta'] = $puntoVenta->codigo_punto_venta;
        $solicitud['cuis'] = $cuis;

        return $this->invocar('codigos', 'cafc', [
            'SolicitudCafc' => $solicitud,
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
