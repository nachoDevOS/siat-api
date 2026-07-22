<?php

namespace App\Services\Siat;

/**
 * Servicio FacturacionSincronizacion: fecha/hora del SIN y todos los catalogos.
 *
 * OJO: nombres de operaciones y campos documentados por el SIN; verificar
 * contra el WSDL vigente antes de produccion (rule 7).
 */
class ServicioSincronizacion extends ServicioBase
{
    /**
     * Fecha y hora oficial del SIN. Se usa para sellar la factura con la hora
     * del servidor tributario, no la del cliente.
     */
    public function fechaHora(): mixed
    {
        return $this->invocar('sincronizacion', 'sincronizarFechaHora', [
            'SolicitudSincronizacion' => $this->solicitudBase(),
        ]);
    }

    /**
     * Actividades economicas registradas del NIT de la empresa.
     */
    public function listaActividades(string $cuis): mixed
    {
        return $this->invocarCatalogo('sincronizarListaActividades', $cuis);
    }

    /**
     * Productos-servicios homologados segun las actividades del NIT.
     */
    public function listaProductosServicios(string $cuis): mixed
    {
        return $this->invocarCatalogo('sincronizarListaProductosServicios', $cuis);
    }

    /**
     * Leyendas de factura por actividad economica.
     */
    public function listaLeyendas(string $cuis): mixed
    {
        return $this->invocarCatalogo('sincronizarListaLeyendasFactura', $cuis);
    }

    /**
     * Mensajes de servicio del SIN.
     */
    public function listaMensajes(string $cuis): mixed
    {
        return $this->invocarCatalogo('sincronizarListaMensajesServicios', $cuis);
    }

    /**
     * Parametrica generica (unidades de medida, tipos de moneda, etc.).
     * El nombre de la operacion varia por parametrica; se recibe como argumento.
     */
    public function parametrica(string $operacion, string $cuis): mixed
    {
        return $this->invocarCatalogo($operacion, $cuis);
    }

    /**
     * Todas las sincronizaciones de catalogo comparten la misma forma de
     * solicitud (base + cuis), asi que se centraliza aca.
     */
    private function invocarCatalogo(string $operacion, string $cuis): mixed
    {
        $solicitud = $this->solicitudBase();
        $solicitud['cuis'] = $cuis;

        return $this->invocar('sincronizacion', $operacion, [
            'SolicitudSincronizacion' => $solicitud,
        ]);
    }
}
