<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SiatException;
use App\Http\Controllers\Controller;
use App\Jobs\EnviarFacturaAlSiat;
use App\Jobs\VerificarEstadoFactura;
use App\Models\Empresa;
use App\Models\Factura;
use App\Services\Factura\AnuladorFactura;
use App\Services\Factura\GeneradorPdf;
use App\Services\Factura\GeneradorQr;
use App\Services\Panel\EstadosVisuales;
use Illuminate\Http\JsonResponse;
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

        // Por defecto, este mes. Salvo al buscar una factura puntual (CUF,
        // referencia o numero): esa tiene que aparecer sea del mes que sea.
        if ($q === '') {
            $filtros = $this->conMesActualPorDefecto($request, $filtros);
        }

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

    public function show(Factura $factura, GeneradorQr $qr, AnuladorFactura $anulador): View
    {
        $factura->load(['empresa', 'puntoVenta.sucursal', 'items', 'anulacion', 'cufd', 'paquete']);

        return view('admin.facturas.show', [
            'factura' => $factura,
            // La misma URL que codifica el QR: consulta la factura en el SIN.
            'urlVerificacion' => $qr->urlVerificacion($factura, GeneradorQr::FORMATO_CARTA),
            'esAnulable' => $anulador->esAnulable($factura),
            'esRevertible' => $anulador->esRevertible($factura),
            'motivosAnulacion' => $anulador->motivos(),
        ]);
    }

    /**
     * Estado actual de la factura y de su anulacion. Lo consulta la ficha
     * mientras espera que el SIAT confirme, para recargarse sola.
     */
    public function estado(Factura $factura): JsonResponse
    {
        return response()->json([
            'estado' => $factura->estado,
            'anulacion' => $factura->anulacion?->estado,
        ]);
    }

    /**
     * Anula la factura con el motivo elegido. Igual que por la API: queda
     * ANULADA al instante y el SIAT la confirma en segundo plano.
     */
    public function anular(Request $request, Factura $factura, AnuladorFactura $anulador): RedirectResponse
    {
        $motivos = array_keys($anulador->motivos());

        $datos = $request->validate([
            'motivo' => ['required', 'integer', Rule::in($motivos)],
        ], ['motivo.in' => 'Elija un motivo del catalogo del SIN.']);

        if (! $anulador->esAnulable($factura)) {
            return back()->with('error', $anulador->yaSeRevirtio($factura)
                ? 'Esta factura ya se anulo y se revirtio una vez: el SIN no permite anularla de nuevo.'
                : "Una factura {$factura->estado} no se puede anular.");
        }

        $anulador->anular($factura, (int) $datos['motivo']);

        return back()->with('estado', 'Factura anulada. El SIAT la confirma en unos segundos.');
    }

    /**
     * Revierte una anulacion confirmada: la factura vuelve a ser valida. Se
     * puede hacer una sola vez por factura.
     */
    public function revertir(Factura $factura, AnuladorFactura $anulador): RedirectResponse
    {
        if (! $anulador->esRevertible($factura)) {
            return back()->with('error', 'Solo se revierte una anulacion que el SIN ya confirmo.');
        }

        try {
            $anulador->revertir($factura);
        } catch (SiatException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('estado', 'Anulacion revertida: la factura vuelve a estar vigente. Ya no se podra anular de nuevo.');
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
