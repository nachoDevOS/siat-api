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
    public function facturacion(Empresa $empresa): ServicioFacturacion
    {
        return new ServicioFacturacion($empresa, new SiatClient($empresa));
    }

    public function codigos(Empresa $empresa): ServicioCodigos
    {
        return new ServicioCodigos($empresa, new SiatClient($empresa));
    }

    public function operaciones(Empresa $empresa): ServicioOperaciones
    {
        return new ServicioOperaciones($empresa, new SiatClient($empresa));
    }

    public function sincronizacion(Empresa $empresa): ServicioSincronizacion
    {
        return new ServicioSincronizacion($empresa, new SiatClient($empresa));
    }
}
