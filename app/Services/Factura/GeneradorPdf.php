<?php

namespace App\Services\Factura;

use App\Models\Catalogo;
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
    public function __construct(
        private readonly GeneradorQr $qr,
        private readonly MontoEnLetras $letras,
    ) {}

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
            // "Son: ..." de la representacion grafica: sobre lo que se paga.
            'montoLiteral' => $this->letras->bolivianos((float) $factura->monto_total - (float) $factura->gift_card),
            // Nombre de cada unidad de medida del catalogo del SIN; si no esta
            // sincronizado, la vista muestra el codigo.
            'unidades' => Catalogo::deTipo('unidades_medida')->pluck('descripcion', 'codigo_clasificador')->all(),
        ]);

        $ruta = "siat/pdf/{$factura->cuf}.pdf";
        Storage::disk('local')->put($ruta, $pdf->output());

        return $ruta;
    }
}
