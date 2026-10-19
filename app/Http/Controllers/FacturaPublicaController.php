<?php

namespace App\Http\Controllers;

use App\Models\Factura;
use App\Services\Factura\GeneradorPdf;
use App\Services\Factura\GeneradorQr;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Factura PUBLICA: lo que abre el sistema de ventas para mostrar o imprimir
 * una factura, y lo que se le comparte al comprador. La API arma todo; el
 * sistema externo no tiene que construir nada ni mandar su token.
 *
 * Toda ruta exige el enlace firmado (Factura::urlPublica): sin firma valida
 * responde 404 y no se puede recorrer facturas cambiando el CUF.
 */
class FacturaPublicaController extends Controller
{
    public function __construct(
        private readonly GeneradorPdf $pdf,
        private readonly GeneradorQr $qr,
    ) {}

    /**
     * Antes de imprimir se elige el formato: hoja carta o ticket de 80 mm.
     * Cada uno se abre directo en el visor de PDF del navegador.
     */
    public function show(string $cuf): View
    {
        $factura = $this->buscar($cuf);

        return view('factura.publica', [
            'factura' => $factura->load('empresa'),
            'urlVerificacion' => $this->qr->urlVerificacion($factura),
            'urlPdf' => $factura->urlPublica('pdf'),
            'urlRollo' => $factura->urlPublica('rollo'),
        ]);
    }

    /**
     * PDF tamano carta (el mismo que se guarda al enviar la factura).
     */
    public function pdf(string $cuf): Response
    {
        $factura = $this->buscar($cuf);

        if (blank($factura->ruta_pdf) || ! Storage::disk('local')->exists($factura->ruta_pdf)) {
            $factura->update(['ruta_pdf' => $this->pdf->generarYGuardar($factura)]);
        }

        return $this->enLinea(Storage::disk('local')->get($factura->ruta_pdf), $this->nombreArchivo($factura).'.pdf');
    }

    /**
     * PDF para impresora termica de ticket (80 mm).
     *
     * La nota de credito-debito no tiene version ticket (lleva dos listas de
     * items y la referencia a la factura): se entrega la de hoja carta.
     */
    public function rollo(string $cuf): Response
    {
        $factura = $this->buscar($cuf);

        if ($factura->esNotaCreditoDebito()) {
            return $this->pdf($cuf);
        }

        return $this->enLinea($this->pdf->rollo($factura), $this->nombreArchivo($factura).'-rollo.pdf');
    }

    /**
     * Mini ticket de caja (monto y QR, sin detalle).
     */
    public function mini(string $cuf): Response
    {
        $factura = $this->buscar($cuf);

        return $this->enLinea($this->pdf->mini($factura), $this->nombreArchivo($factura).'-mini.pdf');
    }

    /**
     * ZIP con el XML firmado y los PDF, para archivar o enviar.
     */
    public function zip(string $cuf): Response
    {
        $factura = $this->buscar($cuf);

        return response($this->pdf->zip($factura), 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$this->nombreArchivo($factura).'.zip"',
        ]);
    }

    private function nombreArchivo(Factura $factura): string
    {
        return ($factura->esNotaCreditoDebito() ? 'nota-credito-debito-' : 'factura-').$factura->numero_factura;
    }

    private function buscar(string $cuf): Factura
    {
        // Sin XML firmado no hay documento que mostrar.
        return Factura::where('cuf', $cuf)->whereNotNull('xml_firmado')->firstOrFail();
    }

    /**
     * 'inline': se abre en el visor del navegador, listo para imprimir.
     */
    private function enLinea(string $contenido, string $nombre): Response
    {
        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$nombre}\"",
        ]);
    }
}
