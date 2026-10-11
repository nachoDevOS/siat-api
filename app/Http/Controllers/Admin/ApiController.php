<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use Illuminate\View\View;

/**
 * Consola de administracion de la API REST v1. Muestra el catalogo de endpoints
 * que consume el sistema de ventas del cliente y el estado de acceso de cada
 * empresa (ambiente, tokens activos y webhook), sin exponer ningun token.
 */
class ApiController extends Controller
{
    public function index(): View
    {
        // Tokens que todavia sirven: los vencidos no cuentan como acceso.
        $empresas = Empresa::withCount([
            'sucursales',
            'tokens as tokens_activos_count' => fn ($q) => $q->where(
                fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()),
            ),
        ])->latest()->get();

        // Catalogo de endpoints publicos de la API v1 con el permiso que exige
        // cada uno (ver routes/api.php).
        $endpoints = [
            ['GET', 'v1/estado', 'Salud de la integracion', 'estado:leer'],
            ['GET', 'v1/catalogos/{tipo}', 'Catalogos para armar la venta', 'catalogos:leer'],
            ['GET', 'v1/sucursales', 'Sucursales con sus puntos de venta', 'estructura:leer'],
            ['GET', 'v1/puntos-venta', 'Listar puntos de venta', 'estructura:leer'],
            ['POST', 'v1/puntos-venta', 'Alta local de punto de venta', 'estructura:gestionar'],
            ['GET', 'v1/puntos-venta/{sucursal}/{pv}/codigos', 'Vigencia de CUIS y CUFD', 'codigos:leer'],
            ['POST', 'v1/puntos-venta/{sucursal}/{pv}/cufd', 'Forzar un CUFD nuevo (emergencia)', 'codigos:renovar'],
            ['GET', 'v1/nit/{nit}/verificar', 'Verificar NIT ante el SIN', 'facturas:emitir'],
            ['GET', 'v1/facturas', 'Listar facturas', 'facturas:leer'],
            ['GET', 'v1/facturas/{cuf}', 'Detalle de una factura', 'facturas:leer'],
            ['GET', 'v1/facturas/{cuf}/pdf', 'Descargar PDF', 'facturas:leer'],
            ['GET', 'v1/facturas/{cuf}/xml', 'Descargar XML firmado', 'facturas:leer'],
            ['POST', 'v1/facturas/{cuf}/anular', 'Anular factura', 'facturas:anular'],
            ['POST', 'v1/facturas', 'Emitir factura (solo produccion)', 'facturas:emitir'],
        ];

        return view('admin.api.index', [
            'baseUrl' => rtrim((string) config('app.url'), '/').'/api/',
            'endpoints' => $endpoints,
            'empresas' => $empresas,
        ]);
    }
}
