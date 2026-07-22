<?php

namespace App\Services\Factura;

use App\Models\Factura;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Genera el PDF imprimible de una factura a partir de la vista Blade
 * resources/views/factura/pdf.blade.php e incrusta el QR de verificacion.
 *
 * El PDF es una representacion; el documento LEGAL es el XML firmado.
 */
class GeneradorPdf
{
    public function __construct(private readonly GeneradorQr $qr) {}

    /**
     * Renderiza el PDF, lo guarda en storage/app/siat/pdf y devuelve la ruta
     * relativa guardada (para persistir en factura.ruta_pdf).
     */
    public function generarYGuardar(Factura $factura): string
    {
        $factura->loadMissing(['items', 'empresa', 'puntoVenta.sucursal']);

        $pdf = Pdf::loadView('factura.pdf', [
            'factura' => $factura,
            'qr' => $this->qr->dataUri($factura),
            'urlVerificacion' => $this->qr->urlVerificacion($factura),
        ]);

        $ruta = "siat/pdf/{$factura->cuf}.pdf";
        Storage::disk('local')->put($ruta, $pdf->output());

        return $ruta;
    }
}
