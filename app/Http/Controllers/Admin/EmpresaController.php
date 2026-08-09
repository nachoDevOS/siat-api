<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Services\Webhooks\DestinoWebhook;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ABM de empresas (clientes contribuyentes) del panel /admin.
 * Aca se da de alta cada cliente y se genera su API key.
 */
class EmpresaController extends Controller
{
    /**
     * Estados validos del ciclo de vida ante el SIN. Se listan para que el
     * formulario no pueda dejar la empresa en un estado inventado.
     *
     * @var list<string>
     */
    private const ESTADOS = [
        Empresa::ESTADO_EN_REGISTRO,
        Empresa::ESTADO_EN_PRUEBAS,
        Empresa::ESTADO_PILOTO_APROBADO,
        Empresa::ESTADO_PRODUCCION,
        Empresa::ESTADO_OBSERVADO,
    ];

    public function index(): View
    {
        $empresas = Empresa::latest()->paginate(20);

        return view('admin.empresas.index', compact('empresas'));
    }

    public function create(): View
    {
        return view('admin.empresas.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validar($request);

        // Se genera la API key una sola vez: se guarda su hash y se muestra la
        // clave en claro al alta. Despues ya no se puede recuperar.
        $apiKey = Str::random(48);
        $datos['api_key_hash'] = hash('sha256', $apiKey);

        $empresa = Empresa::create($datos);

        return redirect()
            ->route('admin.empresas.show', $empresa)
            ->with('api_key', $apiKey)
            ->with('estado', 'Empresa creada. Copie la API key: solo se muestra ahora.');
    }

    public function show(Empresa $empresa): View
    {
        $empresa->load('sucursales.puntosVenta', 'certificados');

        return view('admin.empresas.show', compact('empresa'));
    }

    public function edit(Empresa $empresa): View
    {
        return view('admin.empresas.edit', compact('empresa'));
    }

    public function update(Request $request, Empresa $empresa): RedirectResponse
    {
        // El propio modelo invalida el cache por api_key_hash al guardarse.
        $empresa->update($this->validar($request, $empresa));

        return redirect()
            ->route('admin.empresas.show', $empresa)
            ->with('estado', 'Empresa actualizada.');
    }

    public function destroy(Empresa $empresa): RedirectResponse
    {
        $empresa->delete();

        return redirect()->route('admin.empresas.index')->with('estado', 'Empresa eliminada.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, ?Empresa $empresa = null): array
    {
        return $request->validate([
            'nombre_comercial' => ['required', 'string', 'max:255'],
            'razon_social' => ['required', 'string', 'max:255'],
            'nit' => ['required', 'string', 'max:20'],
            'codigo_sistema' => ['nullable', 'string', 'max:255'],
            'token_delegado' => ['nullable', 'string'],
            'codigo_ambiente' => ['required', 'integer', 'in:1,2'],
            'codigo_modalidad' => ['required', 'integer', 'in:1,2'],
            'estado' => ['required', Rule::in(self::ESTADOS)],
            // Se valida el destino aca y no solo al notificar: asi el operador
            // ve el error al guardar en vez de descubrirlo por webhooks mudos.
            'webhook_url' => ['nullable', 'url', 'max:255', function (string $atributo, mixed $valor, Closure $falla) {
                $motivo = app(DestinoWebhook::class)->motivoRechazo((string) $valor);

                if ($motivo !== null) {
                    $falla($motivo);
                }
            }],
        ]);
    }
}
