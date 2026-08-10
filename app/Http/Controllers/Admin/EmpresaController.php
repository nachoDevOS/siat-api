<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Catalogo;
use App\Models\Empresa;
use App\Models\Factura;
use App\Services\Panel\EstadosVisuales;
use App\Services\Panel\RequisitosEtapa;
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

    /**
     * Listado con filtro por estado y buscador por NIT o nombre. Con varios
     * clientes en distintas etapas, la lista cruda deja de servir.
     */
    public function index(Request $request): View
    {
        $estado = (string) $request->query('estado', '');
        $busqueda = trim((string) $request->query('q', ''));

        $empresas = Empresa::query()
            ->when($estado !== '', fn ($q) => $q->where('estado', $estado))
            ->when($busqueda !== '', fn ($q) => $q->where(function ($sub) use ($busqueda) {
                $sub->where('nombre_comercial', 'like', "%{$busqueda}%")
                    ->orWhere('razon_social', 'like', "%{$busqueda}%")
                    ->orWhere('nit', 'like', "%{$busqueda}%");
            }))
            ->latest()
            ->paginate(20)
            // withQueryString para que el filtro sobreviva al paginado.
            ->withQueryString();

        return view('admin.empresas.index', [
            'empresas' => $empresas,
            'estado' => $estado,
            'busqueda' => $busqueda,
            'estadosDisponibles' => EstadosVisuales::estadosEmpresa(),
        ]);
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

    /**
     * Ficha del cliente: todo lo que necesita para poder facturar, en una sola
     * pantalla, con el checklist de lo que le falta para la siguiente etapa.
     */
    public function show(Empresa $empresa, RequisitosEtapa $requisitos): View
    {
        $empresa->load('sucursales.puntosVenta', 'certificados');

        return view('admin.empresas.show', [
            'empresa' => $empresa,
            'certificado' => $empresa->certificados->firstWhere('activo', true),
            'semaforoCertificado' => $requisitos->semaforoCertificado($empresa),
            'requisitos' => $requisitos->para($empresa),
            'progresoPiloto' => $requisitos->progresoPiloto($empresa),
            // Codigos reales del SIN para el desplegable de tipo de punto de
            // venta, en vez de un numero a mano. Vacio hasta sincronizar.
            'tiposPuntoVenta' => Catalogo::where('tipo', 'tipos_punto_venta')
                ->orderBy('codigo_clasificador')
                ->get(),
        ]);
    }

    /**
     * Avanza (o corrige) la etapa del cliente ante el SIN.
     *
     * El cambio es manual a proposito: quien aprueba el piloto o habilita
     * produccion es el SIN, no este sistema. Aca solo se refleja lo que ya
     * paso afuera.
     */
    public function cambiarEstado(Request $request, Empresa $empresa): RedirectResponse
    {
        $datos = $request->validate([
            'estado' => ['required', Rule::in(self::ESTADOS)],
        ]);

        $empresa->update(['estado' => $datos['estado']]);

        $etiqueta = EstadosVisuales::empresa($datos['estado'])['etiqueta'];

        return redirect()
            ->route('admin.empresas.show', $empresa)
            ->with('estado', "Cliente marcado como {$etiqueta}.");
    }

    public function edit(Empresa $empresa): View
    {
        return view('admin.empresas.edit', compact('empresa'));
    }

    public function update(Request $request, Empresa $empresa): RedirectResponse
    {
        $datos = $this->validar($request, $empresa);

        // El formulario nunca devuelve el token al navegador, asi que un campo
        // vacio significa "no lo toques", no "borralo".
        if (blank($datos['token_delegado'] ?? null)) {
            unset($datos['token_delegado']);
        }

        // El propio modelo invalida el cache por api_key_hash al guardarse.
        $empresa->update($datos);

        return redirect()
            ->route('admin.empresas.show', $empresa)
            ->with('estado', 'Empresa actualizada.');
    }

    /**
     * Borrar una empresa cascadea a sus facturas, que son documentos fiscales
     * con obligacion de conservacion: una vez emitida la primera, el cliente ya
     * no se elimina. Para dejarlo de atender esta el estado OBSERVADO.
     */
    public function destroy(Empresa $empresa): RedirectResponse
    {
        $facturas = Factura::where('empresa_id', $empresa->id)->count();

        if ($facturas > 0) {
            return redirect()
                ->route('admin.empresas.show', $empresa)
                ->with('error', "No se puede eliminar: el cliente tiene {$facturas} factura(s) emitida(s). Cambialo a OBSERVADO para dejar de atenderlo.");
        }

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
