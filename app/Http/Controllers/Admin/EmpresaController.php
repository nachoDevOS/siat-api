<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * ABM de empresas (clientes contribuyentes) del panel /admin.
 * Aca se da de alta cada cliente y se genera su API key.
 */
class EmpresaController extends Controller
{
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
        $empresa->update($this->validar($request, $empresa));

        // La empresa esta cacheada por su api_key_hash; se limpia por las dudas.
        Cache::forget("empresa.apikey.{$empresa->api_key_hash}");

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
            'estado' => ['required', 'string', 'max:30'],
            'webhook_url' => ['nullable', 'url', 'max:255'],
        ]);
    }
}
