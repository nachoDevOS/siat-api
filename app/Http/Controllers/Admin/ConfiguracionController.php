<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Muestra la configuracion activa del proveedor: identidad ante el SIN, URLs
 * por ambiente y parametros del transporte SOAP.
 *
 * Es de SOLO LECTURA a proposito. Estos valores vienen del .env y valen para
 * todos los clientes a la vez: un dedazo aca dejaria sin facturar a todo el
 * mundo de una vez. Existe para poder ver que hay configurado cuando algo
 * falla, sin tener que entrar por SSH a leer el .env.
 *
 * Lo que si cambia por cliente (NIT, token, certificado, ambiente) se edita en
 * la ficha de cada empresa.
 */
class ConfiguracionController extends Controller
{
    public function index(): View
    {
        $proveedor = (array) config('siat.proveedor');

        return view('admin.configuracion.index', [
            'proveedor' => $proveedor,
            // Faltando cualquiera de estos dos, el alta de cliente no puede
            // precargar nada y las llamadas al SIN salen incompletas.
            'faltantes' => array_keys(array_filter(
                ['nit' => $proveedor['nit'] ?? null, 'codigo_sistema' => $proveedor['codigo_sistema'] ?? null],
                fn ($valor): bool => blank($valor),
            )),
            'urls' => (array) config('siat.urls'),
            'servicios' => (array) config('siat.servicios'),
            'transporte' => [
                'Timeout de conexion' => config('siat.connection_timeout').' s',
                'Timeout de lectura' => config('siat.timeout').' s',
                'Traza SOAP (para auditoria)' => config('siat.trace') ? 'activada' : 'desactivada',
                'Cache del WSDL' => config('siat.wsdl_cache'),
                'Limite de la API' => config('siat.api.rate_limit').' peticiones/min por empresa',
            ],
        ]);
    }
}
