<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El SIAT rechazo (observo) la factura. Lleva la lista de observaciones del SIN
 * para poder devolverlas al cliente y corregir.
 */
class FacturaObservadaException extends RuntimeException
{
    /**
     * @param  list<array{codigo: int|string, descripcion: string}>  $observaciones
     */
    public function __construct(
        string $message,
        public readonly array $observaciones = [],
    ) {
        parent::__construct($message);
    }
}
