<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Error al comunicarse con el SIAT o al construir el cliente SOAP.
 * Se usa una excepcion propia para distinguir las fallas del SIN de
 * cualquier otro error de la aplicacion y poder tratarlas distinto.
 */
class SiatException extends RuntimeException
{
    /**
     * Guarda el XML crudo enviado y recibido cuando esta disponible, porque
     * es lo unico que permite depurar de verdad una respuesta del SIN.
     */
    public function __construct(
        string $message,
        public readonly ?string $xmlEnviado = null,
        public readonly ?string $xmlRecibido = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
