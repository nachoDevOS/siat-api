<?php

namespace App\Exceptions;

/**
 * El SIN respondio 980: ya habia un CUIS vigente y devolvio ese en vez de
 * emitir uno. En el piloto la prueba no cuenta; se destraba cerrando las
 * operaciones del sistema en ese punto de venta y pidiendolo de nuevo.
 *
 * Lleva el codigo vigente porque es justo el que exige el cierre: asi no hay
 * que volver a preguntarselo al SIN.
 */
class CuisYaVigenteException extends SiatException
{
    public function __construct(string $message, public readonly string $codigo)
    {
        parent::__construct($message);
    }
}
