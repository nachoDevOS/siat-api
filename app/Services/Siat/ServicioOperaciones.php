<?php

namespace App\Services\Siat;

use App\Models\PuntoVenta;
use Illuminate\Support\Carbon;

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
     * Cierra las operaciones del sistema en una sucursal / punto de venta.
     *
     * VERIFICADO CONTRA EL WSDL DEL PILOTO (2026-10-07): struct
     * solicitudOperaciones { codigoAmbiente, codigoModalidad, codigoPuntoVenta,
     * codigoSistema, codigoSucursal, cuis, nit }, respuesta en
     * RespuestaCierreSistemas.
     */
    public function cerrarOperacionesSistema(int $codigoSucursal, int $codigoPuntoVenta, string $cuis): mixed
    {
        $solicitud = $this->solicitudBase();
        $solicitud['codigoSucursal'] = $codigoSucursal;
        $solicitud['codigoPuntoVenta'] = $codigoPuntoVenta;
        $solicitud['cuis'] = $cuis;

        return $this->invocar('operaciones', 'cierreOperacionesSistema', [
            'SolicitudOperaciones' => $solicitud,
        ]);
    }

    /**
     * Lista los eventos significativos registrados en el SIN para un dia.
     *
     * VERIFICADO CONTRA LOS WSDL DEL PILOTO /v2 y /sfd (2026-10-21): struct
     * solicitudConsultaEvento { codigoAmbiente, codigoPuntoVenta, codigoSistema,
     * codigoSucursal, cufd, cuis, fechaEvento, nit }, respuesta en
     * RespuestaListaEventos.listaCodigos. El SIN busca por la fecha DEL EVENTO
     * (inicio/fin), no por el dia en que se registro, y exige un CUFD vigente
     * (914 si no). Sin eventos ese dia responde 957.
     */
    public function consultarEventos(int $codigoSucursal, int $codigoPuntoVenta, string $cuis, string $cufd, Carbon $fecha): mixed
    {
        $solicitud = $this->solicitudBase();
        // No es parte de solicitudConsultaEvento.
        unset($solicitud['codigoModalidad']);
        $solicitud['codigoSucursal'] = $codigoSucursal;
        $solicitud['codigoPuntoVenta'] = $codigoPuntoVenta;
        $solicitud['cuis'] = $cuis;
        $solicitud['cufd'] = $cufd;
        $solicitud['fechaEvento'] = $fecha->copy()->startOfDay()->format('Y-m-d\TH:i:s.v');

        return $this->invocar('operaciones', 'consultaEventoSignificativo', [
            'SolicitudConsultaEvento' => $solicitud,
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
}
