<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * La venta no paso las reglas de negocio locales, asi que ni siquiera se
 * intenta armar el XML ni gastar un viaje al SIAT.
 *
 * Es distinta de una factura OBSERVADA: aca el rechazo lo decidimos nosotros
 * antes de enviar; en una observada lo decidio el SIN despues de recibir, y
 * el motivo queda en facturas.motivo_observacion.
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
