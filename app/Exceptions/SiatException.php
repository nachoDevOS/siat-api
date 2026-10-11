<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Error al comunicarse con el SIAT o al construir el cliente SOAP.
 * Se usa una excepcion propia para distinguir las fallas del SIN de
 * cualquier otro error de la aplicacion y poder tratarlas distinto.
 *
 * El XML enviado y recibido de cada llamada no viaja en la excepcion: queda
 * en logs_siat (ServicioBase::registrarLog), que es donde se depura.
 */
class SiatException extends RuntimeException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
