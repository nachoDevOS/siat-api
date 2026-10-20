<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SiatException;
use App\Http\Controllers\Controller;
use App\Models\ActividadEconomica;
use App\Models\Catalogo;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\LeyendaFactura;
use App\Models\ProductoServicio;
use App\Services\Panel\CreadorSandbox;
use App\Services\Panel\EstadosVisuales;
use App\Services\Panel\PuntosVentaSiat;
use App\Services\Panel\RequisitosEtapa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

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
            ->withCount('facturas')
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

        $empresa = Empresa::create($datos);

        // El acceso a la API ya no nace con la empresa: se crean tokens desde
        // su ficha, uno por cada sistema de ventas y con el perfil que toque.
        return redirect()
            ->route('admin.empresas.show', $empresa)
            ->with('estado', 'Empresa creada. Para conectar su sistema de ventas, crea un token en "Acceso API".');
    }

    /**
     * Ficha del cliente: todo lo que necesita para poder facturar, en una sola
     * pantalla, con el checklist de lo que le falta para la siguiente etapa.
     */
    public function show(Empresa $empresa, RequisitosEtapa $requisitos, PuntosVentaSiat $puntosVentaSiat): View
    {
        // withCount evita una consulta por tarjeta de punto de venta al pintar
        // cuantas facturas lleva emitidas cada uno.
        $empresa->load([
            // La sucursal va primero: si se cargara despues, pisaria la relacion
            // anidada que ya se habia resuelto y los puntos de venta quedarian
            // sin su conteo.
            'sucursales' => fn ($q) => $q->orderBy('codigo_sucursal'),
            'sucursales.puntosVenta' => fn ($q) => $q->withCount('facturas')->orderBy('codigo_punto_venta'),
            // El historial de codigos se pinta por punto de venta: sin esto
            // serian dos consultas por cada uno al abrir el desplegable.
            'sucursales.puntosVenta.cuis' => fn ($q) => $q->latest('id'),
            'sucursales.puntosVenta.cuis.cufds' => fn ($q) => $q->latest('id'),
            'sucursales.puntosVenta.cufds' => fn ($q) => $q->latest('id'),
            'certificados',
            'cafcActivo',
        ]);

        return view('admin.empresas.show', [
            'empresa' => $empresa,
            'certificado' => $empresa->certificados->firstWhere('activo', true),
            'semaforoCertificado' => $requisitos->semaforoCertificado($empresa),
            'cafc' => $empresa->cafcActivo,
            'requisitos' => $requisitos->para($empresa),
            'progresoPiloto' => $requisitos->progresoPiloto($empresa),
            // Resumen del acceso API; el detalle esta en su propia pantalla.
            'tokensActivos' => $empresa->tokens()->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count(),
            // Cuanto hay de cada catalogo del NIT: sin productos homologados
            // la emision no puede resolver la actividad de cada item.
            'catalogosNit' => [
                'actividades' => ActividadEconomica::where('empresa_id', $empresa->id)->count(),
                'productos' => ProductoServicio::where('empresa_id', $empresa->id)->count(),
                'leyendas' => LeyendaFactura::where('empresa_id', $empresa->id)->count(),
            ],
            // Codigos reales del SIN para el desplegable de tipo de punto de
            // venta, en vez de un numero a mano. Vacio hasta sincronizar.
            'tiposPuntoVenta' => Catalogo::where('tipo', 'tipos_punto_venta')
                ->orderBy('codigo_clasificador')
                ->get(),
            // Lo que el SIN tiene registrado en cada sucursal, indexado por su
            // id. Es lo que manda: un punto de venta que solo existe en nuestra
            // base no puede emitir. Va cacheado, no golpea al SIAT en cada carga.
            'puntosVentaSiat' => $empresa->sucursales
                ->mapWithKeys(fn ($sucursal) => [$sucursal->id => $puntosVentaSiat->deSucursal($sucursal)]),
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

    /**
     * Crea el sandbox (copia en el ambiente piloto) de un cliente en
     * produccion, para que su sistema de ventas pruebe sin validez fiscal.
     */
    public function crearSandbox(Empresa $empresa, CreadorSandbox $creador): RedirectResponse
    {
        try {
            $sandbox = $creador->crear($empresa);
        } catch (RuntimeException|SiatException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.empresas.edit', $sandbox)
            ->with('estado', 'Sandbox creado con certificado de prueba. Falta pegar el token delegado del PILOTO del SIN (el de produccion no sirve) y despues generar su token de API (siat_test_).');
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

        $cambiaAmbiente = (int) $datos['codigo_ambiente'] !== $empresa->codigo_ambiente;

        $empresa->update($datos);

        // Un token es de un ambiente (siat_test_ / siat_live_): si la empresa
        // pasa del piloto a produccion, el token con el que el cliente probo
        // no puede seguir emitiendo, ahora facturas con validez fiscal. Se
        // revocan y se emite uno nuevo del ambiente nuevo.
        if ($cambiaAmbiente && $empresa->tokens()->exists()) {
            $empresa->tokens()->delete();

            return redirect()
                ->route('admin.empresas.api', $empresa)
                ->with('estado', 'Empresa actualizada. Cambio el ambiente: el token de API anterior quedo REVOCADO. Genera uno nuevo y entregaselo al cliente.');
        }

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
            // codigoInterno de la cabecera del XML V3: de 1 a 10 caracteres.
            // Si no viene, queda el que tenia (o el default "A").
            'codigo_interno' => ['sometimes', 'required', 'string', 'max:10'],
            'token_delegado' => ['nullable', 'string'],
            'codigo_ambiente' => ['required', 'integer', 'in:1,2'],
            // Solo electronica en linea (1). La computarizada usa otro nodo
            // raiz en el XML y no exige firma: aceptarla aca produciria un
            // documento hibrido que el SIN rechaza sin decir por que. Ademas
            // la autorizacion R-1359 del proveedor es de modalidad 1.
            'codigo_modalidad' => ['required', 'integer', 'in:1'],
            'estado' => ['required', Rule::in(self::ESTADOS)],
            // La URL del webhook se edita en "Acceso API" (AccesoApiController).
        ]);
    }
}
