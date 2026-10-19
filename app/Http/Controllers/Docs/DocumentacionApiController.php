<?php

namespace App\Http\Controllers\Docs;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Documentacion PUBLICA de la API para los sistemas de venta que se integran:
 * /docs/api es una app React (resources/js/docs) con guias, referencia de
 * cada endpoint, ejemplos de codigo y buscador, al estilo de los portales de
 * documentacion de otras APIs.
 *
 * Fuente unica: las guias salen de docs/integracion/API.md y la referencia
 * de docs/integracion/openapi.yaml (un test la compara con las rutas
 * reales). La app las lee al compilarse, asi que no pueden desincronizarse.
 *
 * Es publica a proposito: solo describe el contrato, igual que la carpeta
 * docs/integracion que se le entrega al cliente. Lo privado (token, secreto,
 * codigos) nunca esta aca.
 */
class DocumentacionApiController extends Controller
{
    /**
     * Fuente unica de la especificacion. Un test la compara con las rutas
     * reales, asi que esta pagina no puede quedar desactualizada.
     */
    private const ESPECIFICACION = 'docs/integracion/openapi.yaml';

    /**
     * Coleccion de Postman / Thunder Client, para importar y probar.
     */
    public function coleccionPostman(): Response
    {
        return $this->descargaJson('docs/integracion/postman/coleccion.json', 'siat-api.postman_collection.json');
    }

    /**
     * Entorno de Postman con base_url ya apuntando a esta instalacion: el
     * cliente solo tiene que pegar su token y sus codigos.
     */
    public function entornoPostman(): Response
    {
        return $this->descargaJson('docs/integracion/postman/entorno.json', 'siat-api.postman_environment.json', function (array $entorno): array {
            foreach ($entorno['values'] as $i => $variable) {
                if ($variable['key'] === 'base_url') {
                    $entorno['values'][$i]['value'] = rtrim((string) config('app.url'), '/').'/api/v1';
                }
            }

            return $entorno;
        });
    }

    /**
     * Cualquier pagina de la documentacion (/docs/api y /docs/api/{pagina}):
     * siempre la misma app, que elige la pagina por la URL. Asi cada pagina
     * tiene su enlace propio para compartir.
     */
    public function index(): View
    {
        return view('docs.app', [
            'datos' => [
                'marca' => config('sitio.marca'),
                'logo' => config('sitio.logo'),
                'base' => '/'.trim(parse_url(route('docs.api'), PHP_URL_PATH) ?: 'docs/api', '/'),
                'api_url' => rtrim((string) config('app.url'), '/').'/api/v1',
                'mcp_url' => rtrim((string) config('app.url'), '/').'/mcp',
                'whatsapp' => filled(config('sitio.whatsapp')) ? preg_replace('/\D/', '', (string) config('sitio.whatsapp')) : null,
                'rutas' => [
                    'sitio' => route('sitio.inicio'),
                    'contacto' => route('sitio.inicio').'#contacto',
                    'openapi' => route('docs.api.especificacion'),
                    'coleccion' => route('docs.api.postman.coleccion'),
                    'entorno' => route('docs.api.postman.entorno'),
                ],
            ],
        ]);
    }

    /**
     * La especificacion con el servidor REAL de esta instalacion en vez del
     * de ejemplo, para que "Try it" y los ejemplos apunten al lugar correcto.
     *
     * Se reemplaza el bloque 'servers' como texto: el parser de YAML solo
     * esta en las dependencias de desarrollo y no existe en produccion.
     */
    public function especificacion(): Response
    {
        $yaml = (string) file_get_contents(base_path(self::ESPECIFICACION));
        $servidor = rtrim((string) config('app.url'), '/').'/api/v1';

        $yaml = (string) preg_replace(
            '/^servers:\n(?:[ \t]+.*\n)+/m',
            "servers:\n  - url: {$servidor}\n",
            $yaml,
            1,
        );

        return response($yaml, 200, [
            'Content-Type' => 'application/yaml; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * @param  (callable(array<string, mixed>): array<string, mixed>)|null  $ajustar
     */
    private function descargaJson(string $ruta, string $nombre, ?callable $ajustar = null): Response
    {
        $datos = json_decode((string) file_get_contents(base_path($ruta)), true);

        if ($ajustar !== null) {
            $datos = $ajustar($datos);
        }

        return response(json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$nombre}\"",
        ]);
    }
}
