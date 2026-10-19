<?php

use App\Mcp\Servers\SiatServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| Servidor MCP — la API de facturacion para agentes de IA.
|--------------------------------------------------------------------------
|
| Mismo token de empresa (Authorization: Bearer ...) y los mismos middleware
| que /api/v1: registro en logs_api, autenticacion y limite por minuto. Cada
| herramienta exige el permiso de su endpoint REST (HerramientaSiat).
|
*/

Mcp::web('/mcp', SiatServer::class)
    ->middleware(['siat.registro', 'siat.token', 'siat.rate']);
