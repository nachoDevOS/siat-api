<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El punto de venta no tiene un CUFD vigente y no se pudo obtener uno nuevo.
 * Sin CUFD no se puede calcular el CUF, asi que la emision se detiene.
 */
class CufdVencidoException extends RuntimeException {}
