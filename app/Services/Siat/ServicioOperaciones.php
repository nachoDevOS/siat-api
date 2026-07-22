<?php

namespace App\Services\Siat;

use App\Models\PuntoVenta;

/**
 * Servicio FacturacionOperaciones: alta/consulta/cierre de puntos de venta y
 * eventos significativos.
 *
 * OJO: nombres de operaciones y campos documentados por el SIN; verificar
 * contra el WSDL vigente antes de produccion (rule 7).
 */
class ServicioOperaciones extends ServicioBase
{
    /**
     * Registra un punto de venta en el SIAT. El SIN devuelve el codigo con el
     * que quedara identificado.
     */
    public function registrarPuntoVenta(PuntoVenta $puntoVenta, string $cuis): mixed
    {
        $solicitud = $this->solicitudBase();
        $solicitud['codigoSucursal'] = $puntoVenta->sucursal->codigo_sucursal;
        $solicitud['cuis'] = $cuis;
        $solicitud['nombrePuntoVenta'] = $puntoVenta->nombre;
        $solicitud['descripcion'] = $puntoVenta->nombre;
        $solicitud['codigoTipoPuntoVenta'] = $puntoVenta->tipo_punto_venta;

        return $this->invocar('operaciones', 'registroPuntoVenta', [
            'SolicitudRegistroPuntoVenta' => $solicitud,
        ]);
    }

    /**
     * Consulta los puntos de venta habilitados de una sucursal.
     */
    public function consultarPuntosVenta(int $codigoSucursal, string $cuis): mixed
    {
        $solicitud = $this->solicitudBase();
        $solicitud['codigoSucursal'] = $codigoSucursal;
        $solicitud['cuis'] = $cuis;

        return $this->invocar('operaciones', 'consultaPuntoVenta', [
            'SolicitudConsultaPuntoVenta' => $solicitud,
        ]);
    }

    /**
     * Cierra un punto de venta. Un PV cerrado no vuelve a abrirse.
     */
    public function cerrarPuntoVenta(PuntoVenta $puntoVenta, string $cuis): mixed
    {
        $solicitud = $this->solicitudBase();
        $solicitud['codigoSucursal'] = $puntoVenta->sucursal->codigo_sucursal;
        $solicitud['codigoPuntoVenta'] = $puntoVenta->codigo_punto_venta;
        $solicitud['cuis'] = $cuis;

        return $this->invocar('operaciones', 'cierrePuntoVenta', [
            'SolicitudCierrePuntoVenta' => $solicitud,
        ]);
    }

    /**
     * Registra un evento significativo (para habilitar contingencia).
     *
     * @param  array<string, mixed>  $datosEvento
     */
    public function registrarEvento(array $datosEvento): mixed
    {
        $solicitud = array_merge($this->solicitudBase(), $datosEvento);

        return $this->invocar('operaciones', 'registroEventoSignificativo', [
            'SolicitudEventoSignificativo' => $solicitud,
        ]);
    }

    /**
     * Consulta un evento significativo por su fecha o codigo.
     *
     * @param  array<string, mixed>  $filtro
     */
    public function consultarEvento(array $filtro): mixed
    {
        $solicitud = array_merge($this->solicitudBase(), $filtro);

        return $this->invocar('operaciones', 'consultaEventoSignificativo', [
            'SolicitudConsultaEvento' => $solicitud,
        ]);
    }
}
