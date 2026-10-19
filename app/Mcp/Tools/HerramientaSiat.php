<?php

namespace App\Mcp\Tools;

use App\Models\Empresa;
use App\Models\Factura;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Base de las herramientas MCP. El servidor MCP corre detras de los mismos
 * middleware que la API REST (token de Sanctum, limite, registro): la empresa
 * ya viene en la peticion HTTP y cada herramienta exige el mismo permiso que
 * su endpoint, asi un agente con un token de caja tampoco puede anular.
 */
abstract class HerramientaSiat extends Tool
{
    protected function empresa(): Empresa
    {
        return request()->attributes->get('empresa');
    }

    /**
     * Error listo para devolver si el token no tiene el permiso; null si lo tiene.
     */
    protected function sinPermiso(string $permiso): ?Response
    {
        return $this->empresa()->tokenCan($permiso)
            ? null
            : Response::error("El token no tiene el permiso '{$permiso}'.");
    }

    protected function buscarFactura(string $cuf): ?Factura
    {
        return Factura::where('empresa_id', $this->empresa()->id)
            ->where('cuf', $cuf)
            ->with(['puntoVenta.sucursal', 'facturaOrigen', 'anulacion'])
            ->first();
    }

    /**
     * @param  array<string, mixed>|object  $datos
     */
    protected function json(array|object $datos): Response
    {
        return Response::text((string) json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
