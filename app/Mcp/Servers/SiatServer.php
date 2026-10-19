<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AnularFactura;
use App\Mcp\Tools\ConsultarFactura;
use App\Mcp\Tools\EmitirFactura;
use App\Mcp\Tools\EmitirNotaCreditoDebito;
use App\Mcp\Tools\EstadoIntegracion;
use App\Mcp\Tools\ListarFacturas;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Servidor MCP: la misma facturacion de la API REST, como herramientas que un
 * agente de IA puede llamar. Se autentica con el mismo token de empresa.
 */
#[Name('Facturacion SIAT')]
#[Version('1.0.0')]
#[Instructions('Facturacion electronica en linea ante el SIAT (Impuestos Nacionales de Bolivia) para la empresa del token. Llame primero a estado-integracion: si el ambiente es PILOTO lo emitido no tiene validez fiscal. Emita con referencia_externa para no duplicar. Una devolucion es una nota de credito-debito, no una anulacion.')]
class SiatServer extends Server
{
    protected array $tools = [
        EstadoIntegracion::class,
        EmitirFactura::class,
        ConsultarFactura::class,
        ListarFacturas::class,
        AnularFactura::class,
        EmitirNotaCreditoDebito::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
