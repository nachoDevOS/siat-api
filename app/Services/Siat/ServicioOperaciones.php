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
        return $this->registrarPuntoVentaPara(
            (int) $puntoVenta->sucursal->codigo_sucursal,
            (string) $puntoVenta->nombre,
            (int) $puntoVenta->tipo_punto_venta,
            $cuis,
        );
    }

    /**
     * Registra un punto de venta a partir de valores sueltos, sin necesitar un
     * registro local previo.
     *
     * Hace falta porque el CODIGO lo asigna el SIN en la respuesta: crear la
     * fila local antes obligaba a inventarle un codigo y despues pisarlo. Aca se
     * llama primero y se crea la fila con el codigo real.
     */
    public function registrarPuntoVentaPara(
        int $codigoSucursal,
        string $nombre,
        int $codigoTipoPuntoVenta,
        string $cuis,
    ): mixed {
        $solicitud = $this->solicitudBase();
        $solicitud['codigoSucursal'] = $codigoSucursal;
        $solicitud['cuis'] = $cuis;
        $solicitud['nombrePuntoVenta'] = $nombre;
        $solicitud['descripcion'] = $nombre;
        $solicitud['codigoTipoPuntoVenta'] = $codigoTipoPuntoVenta;

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
