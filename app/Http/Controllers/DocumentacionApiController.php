<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Documentacion PUBLICA de la API para los sistemas de venta que se integran:
 * /docs/api muestra la especificacion OpenAPI con Redoc (buscador, menu,
 * ejemplos y esquemas), como hacen otros gateways del Estado.
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

    public function index(): View
    {
        return view('docs.api', [
            'nombreSistema' => (string) config('siat.proveedor.nombre_sistema'),
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
