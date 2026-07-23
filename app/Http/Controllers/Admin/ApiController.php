<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use Illuminate\View\View;

/**
 * Consola de administracion de la API REST v1. Muestra el catalogo de endpoints
 * que consume el sistema de ventas del cliente y el estado de acceso de cada
 * empresa (ambiente, credencial y webhook), sin exponer la API key en claro.
 */
class ApiController extends Controller
{
    public function index(): View
    {
        $empresas = Empresa::withCount('sucursales')->latest()->get();

        // Catalogo de endpoints publicos de la API v1 (ver routes/api.php).
        $endpoints = [
            ['GET', 'v1/estado', 'Salud de la integracion'],
            ['GET', 'v1/catalogos/{tipo}', 'Catalogos para armar la venta'],
            ['GET', 'v1/puntos-venta', 'Listar puntos de venta'],
            ['POST', 'v1/puntos-venta', 'Alta de punto de venta'],
            ['GET', 'v1/facturas', 'Listar facturas'],
            ['GET', 'v1/facturas/{cuf}', 'Detalle de una factura'],
            ['GET', 'v1/facturas/{cuf}/pdf', 'Descargar PDF'],
            ['GET', 'v1/facturas/{cuf}/xml', 'Descargar XML firmado'],
            ['POST', 'v1/facturas/{cuf}/anular', 'Anular factura'],
            ['POST', 'v1/facturas', 'Emitir factura (solo produccion)'],
        ];

        return view('admin.api.index', [
            'baseUrl' => rtrim((string) config('app.url'), '/').'/api/',
            'endpoints' => $endpoints,
            'empresas' => $empresas,
        ]);
    }
}
