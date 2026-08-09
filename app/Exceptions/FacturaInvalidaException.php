<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * La venta no paso las reglas de negocio locales, asi que ni siquiera se
 * intenta armar el XML ni gastar un viaje al SIAT.
 *
 * Es distinta de FacturaObservadaException: aca el rechazo lo decidimos
 * nosotros antes de enviar; alla lo decidio el SIN despues de recibir.
 */
class FacturaInvalidaException extends RuntimeException
{
    /**
     * @param  list<string>  $errores
     */
    public function __construct(public readonly array $errores)
    {
        parent::__construct('La factura no cumple las validaciones locales.');
    }
}
