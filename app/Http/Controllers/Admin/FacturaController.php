<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\EnviarFacturaAlSiat;
use App\Jobs\VerificarEstadoFactura;
use App\Models\Empresa;
use App\Models\Factura;
use App\Services\Factura\GeneradorPdf;
use App\Services\Panel\EstadosVisuales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Facturas de todos los clientes, para soporte: buscar una, ver su estado y
 * por que el SIN la observo, descargarla y empujar el envio o la
 * verificacion sin esperar a la tarea programada.
 *
 * Antes no habia pantalla: para contestar "mi factura no salio" habia que ir
 * a la base de datos.
 */
class FacturaController extends Controller
{
    public function index(Request $request): View
    {
        $filtros = $request->validate([
            'empresa' => ['nullable', 'integer'],
            'estado' => ['nullable', Rule::in(Factura::ESTADOS)],
            // CUF, referencia externa o numero de factura.
            'q' => ['nullable', 'string', 'max:255'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $q = trim((string) ($filtros['q'] ?? ''));

        $facturas = Factura::query()
            ->with(['empresa', 'puntoVenta.sucursal'])
            ->when($filtros['empresa'] ?? null, fn ($query, $id) => $query->where('empresa_id', $id))
            ->when($filtros['estado'] ?? null, fn ($query, $estado) => $query->where('estado', $estado))
            ->when($filtros['desde'] ?? null, fn ($query, $desde) => $query->whereDate('fecha_emision', '>=', $desde))
            ->when($filtros['hasta'] ?? null, fn ($query, $hasta) => $query->whereDate('fecha_emision', '<=', $hasta))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('cuf', $q)
                ->orWhere('referencia_externa', $q)
                ->when(ctype_digit($q), fn ($w) => $w->orWhere('numero_factura', (int) $q))))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.facturas.index', [
            'facturas' => $facturas,
            'empresas' => Empresa::orderBy('nombre_comercial')->get(['id', 'nombre_comercial']),
            'estados' => collect(Factura::ESTADOS)->mapWithKeys(fn ($e) => [$e => EstadosVisuales::factura($e)['etiqueta']]),
            'filtros' => $filtros + ['q' => $q],
        ]);
    }

    public function show(Factura $factura): View
    {
        $factura->load(['empresa', 'puntoVenta.sucursal', 'items', 'anulacion', 'cufd', 'paquete']);

        return view('admin.facturas.show', ['factura' => $factura]);
    }

    /**
     * Vuelve a preguntar al SIN el estado, sin esperar a la tarea de cada 15
     * minutos. Solo tiene sentido con una factura que el SIN ya recibio.
     */
    public function verificar(Factura $factura): RedirectResponse
    {
        if (! in_array($factura->estado, [Factura::ESTADO_RECIBIDA, Factura::ESTADO_ENVIADA], true)) {
            return back()->with('error', "Una factura {$factura->estado} no se verifica: el SIN todavia no la recibio o ya tiene estado final.");
        }

        VerificarEstadoFactura::dispatch($factura->id);

        return back()->with('estado', 'Verificacion encolada: el estado se actualiza en unos segundos.');
    }

    /**
     * Encola el envio de una factura PENDIENTE sin esperar a la tarea de cada
     * 5 minutos (por ejemplo, despues de levantar un worker que estaba caido).
     */
    public function reenviar(Factura $factura): RedirectResponse
    {
        if ($factura->estado !== Factura::ESTADO_PENDIENTE) {
            return back()->with('error', "Solo se reenvia una factura PENDIENTE; esta esta {$factura->estado}.");
        }

        EnviarFacturaAlSiat::dispatch($factura->id);

        return back()->with('estado', 'Envio encolado.');
    }

    public function pdf(Factura $factura, GeneradorPdf $generador): Response
    {
        if (blank($factura->ruta_pdf) || ! Storage::disk('local')->exists($factura->ruta_pdf)) {
            abort_if(blank($factura->xml_firmado), 404);
            $factura->update(['ruta_pdf' => $generador->generarYGuardar($factura)]);
        }

        return response(Storage::disk('local')->get($factura->ruta_pdf), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"factura-{$factura->numero_factura}.pdf\"",
        ]);
    }

    public function xml(Factura $factura): Response
    {
        abort_if(blank($factura->xml_firmado), 404);

        return response($factura->xml_firmado, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"factura-{$factura->numero_factura}.xml\"",
        ]);
    }
}
