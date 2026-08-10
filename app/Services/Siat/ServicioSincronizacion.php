<?php

namespace App\Services\Siat;

/**
 * Servicio FacturacionSincronizacion: fecha/hora del SIN y todos los catalogos.
 *
 * VERIFICADO CONTRA EL WSDL DEL PILOTO (2026-08-10). El contrato declara:
 *
 *     struct solicitudSincronizacion {
 *         int codigoAmbiente; int codigoPuntoVenta; string codigoSistema;
 *         int codigoSucursal; string cuis; long nit;
 *     }
 *
 * O sea: TODA operacion de este servicio —incluida sincronizarFechaHora— exige
 * sucursal y punto de venta. Sin ellos ext-soap ni siquiera envia: corta con
 * "object has no 'codigoSucursal' property".
 */
class ServicioSincronizacion extends ServicioBase
{
    /**
     * Fecha y hora oficial del SIN. Se usa para sellar la factura con la hora
     * del servidor tributario, no la del cliente.
     */
    public function fechaHora(string $cuis, int $codigoSucursal = 0, int $codigoPuntoVenta = 0): mixed
    {
        // Comparte la struct solicitudSincronizacion con las parametricas, asi
        // que exige cuis igual que ellas: no es una operacion "libre".
        return $this->invocarCatalogo('sincronizarFechaHora', $cuis, $codigoSucursal, $codigoPuntoVenta);
    }

    /**
     * Actividades economicas registradas del NIT de la empresa.
     *
     * La operacion se llama 'sincronizarActividades', no
     * 'sincronizarListaActividades' como decia antes este archivo.
     */
    public function listaActividades(string $cuis, int $codigoSucursal = 0, int $codigoPuntoVenta = 0): mixed
    {
        return $this->invocarCatalogo('sincronizarActividades', $cuis, $codigoSucursal, $codigoPuntoVenta);
    }

    /**
     * Productos-servicios homologados segun las actividades del NIT.
     */
    public function listaProductosServicios(string $cuis, int $codigoSucursal = 0, int $codigoPuntoVenta = 0): mixed
    {
        return $this->invocarCatalogo('sincronizarListaProductosServicios', $cuis, $codigoSucursal, $codigoPuntoVenta);
    }

    /**
     * Leyendas de factura por actividad economica.
     */
    public function listaLeyendas(string $cuis, int $codigoSucursal = 0, int $codigoPuntoVenta = 0): mixed
    {
        return $this->invocarCatalogo('sincronizarListaLeyendasFactura', $cuis, $codigoSucursal, $codigoPuntoVenta);
    }

    /**
     * Mensajes de servicio del SIN.
     */
    public function listaMensajes(string $cuis, int $codigoSucursal = 0, int $codigoPuntoVenta = 0): mixed
    {
        return $this->invocarCatalogo('sincronizarListaMensajesServicios', $cuis, $codigoSucursal, $codigoPuntoVenta);
    }

    /**
     * Parametrica generica (unidades de medida, tipos de moneda, etc.).
     * El nombre de la operacion varia por parametrica; se recibe como argumento.
     */
    public function parametrica(string $operacion, string $cuis, int $codigoSucursal = 0, int $codigoPuntoVenta = 0): mixed
    {
        return $this->invocarCatalogo($operacion, $cuis, $codigoSucursal, $codigoPuntoVenta);
    }

    /**
     * Todas las sincronizaciones de catalogo comparten la misma forma de
     * solicitud, asi que se centraliza aca.
     */
    private function invocarCatalogo(
        string $operacion,
        string $cuis,
        int $codigoSucursal = 0,
        int $codigoPuntoVenta = 0,
    ): mixed {
        $solicitud = $this->solicitudBase();
        $solicitud['codigoSucursal'] = $codigoSucursal;
        $solicitud['codigoPuntoVenta'] = $codigoPuntoVenta;
        $solicitud['cuis'] = $cuis;

        return $this->invocar('sincronizacion', $operacion, [
            'SolicitudSincronizacion' => $solicitud,
        ]);
    }
}
