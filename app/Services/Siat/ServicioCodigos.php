<?php

namespace App\Services\Siat;

use App\Models\PuntoVenta;

/**
 * Servicio FacturacionCodigos: verificar comunicacion, CUIS, CUFD, CAFC y
 * verificar NIT.
 *
 * OJO: los nombres de operaciones y de campos son los documentados por el SIN
 * pero DEBEN confirmarse contra el WSDL vigente antes de produccion (rule 7).
 */
class ServicioCodigos extends ServicioBase
{
    /**
     * Prueba liviana de que hay comunicacion con el SIAT.
     */
    public function verificarComunicacion(): mixed
    {
        return $this->invocar('codigos', 'verificarComunicacion', [
            'SolicitudOperaciones' => $this->solicitudBase(),
        ]);
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
     */
    public function verificarNit(string $nit, string $cuis): mixed
    {
        $solicitud = $this->solicitudBase();
        $solicitud['nitParaVerificacion'] = (int) $nit;
        $solicitud['cuis'] = $cuis;

        return $this->invocar('codigos', 'verificarNit', [
            'SolicitudVerificarNit' => $solicitud,
        ]);
    }
}
