<?php

namespace App\Services\Siat;

use App\Models\Empresa;

/**
 * Construye los servicios SOAP de una empresa.
 *
 * Los jobs hacian "new ServicioFacturacion(...)" a mano, lo que ataba su logica
 * (reintentos, contingencia, mapeo de estados) a una conexion real: no habia
 * forma de probarla sin el SIAT del SIN al otro lado. Resolviendo la fabrica
 * desde el contenedor se puede sustituir en las pruebas.
 */
class FabricaServicios
{
    /**
     * Cliente SOAP crudo. Solo lo necesitan las herramientas de diagnostico
     * que miran el transporte (WSDL, operaciones expuestas); para operar
     * siempre se usa uno de los servicios de abajo.
     */
    public function cliente(Empresa $empresa): SiatClient
    {
        return new SiatClient($empresa);
    }

    public function facturacion(Empresa $empresa): ServicioFacturacion
    {
        return new ServicioFacturacion($empresa, new SiatClient($empresa));
    }

    /**
     * La version solo cambia en las pruebas del piloto que el portal pide
     * tambien contra las rutas /v2 (CUIS de la etapa I).
     */
    public function codigos(Empresa $empresa, string $version = SiatClient::VERSION_V3): ServicioCodigos
    {
        return new ServicioCodigos($empresa, new SiatClient($empresa, $version));
    }

    /**
     * Con version solo la pide el cierre de operaciones de la etapa I, que se
     * hace por la misma ruta (/v2 o /sfd) que la prueba que destraba.
     */
    public function operaciones(Empresa $empresa, string $version = SiatClient::VERSION_V3): ServicioOperaciones
    {
        return new ServicioOperaciones($empresa, new SiatClient($empresa, $version));
    }

    public function sincronizacion(Empresa $empresa): ServicioSincronizacion
    {
        return new ServicioSincronizacion($empresa, new SiatClient($empresa));
    }
}
