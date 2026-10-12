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
        $pdf = Pdf::loadView('factura.pdf', $this->datos($factura, GeneradorQr::FORMATO_CARTA))
            ->setPaper('letter');

        $ruta = "siat/pdf/{$factura->cuf}.pdf";
        Storage::disk('local')->put($ruta, $pdf->output());

        return $ruta;
    }

    /**
     * PDF para impresora termica de ticket (rollo), el formato de
     * las cajas de ferreterias y restaurantes. No se guarda: es barato y se
     * genera al pedirlo.
     *
     * dompdf necesita un alto fijo: se calcula por la cantidad de items para
     * que el ticket no salga cortado ni con un metro de papel en blanco.
     */
    public function rollo(Factura $factura): string
    {
        $datos = $this->datos($factura, GeneradorQr::FORMATO_ROLLO);
        $ancho = 240; // el mismo ancho del ticket del SIN (unos 85 mm)
        $alto = 579 + 35 * $factura->items->count() + (filled($factura->leyenda) ? 35 : 0);

        return Pdf::loadView('factura.rollo', $datos)
            ->setPaper([0, 0, $ancho, $alto])
            ->output();
    }

    /**
     * Todo lo que necesita una representacion grafica, ya resuelto: la usan
     * el PDF carta, el rollo y la vista publica de la factura.
     *
     * @param  int  $formato  GeneradorQr::FORMATO_ROLLO o FORMATO_CARTA (va
     *                        en el QR como parametro 't').
     * @return array<string, mixed>
     */
    public function datos(Factura $factura, int $formato = GeneradorQr::FORMATO_CARTA): array
    {
        $factura->loadMissing(['items', 'empresa', 'puntoVenta.sucursal']);

        return [
            'factura' => $factura,
            'qr' => $this->qr->dataUri($factura, 150, $formato),
            'urlVerificacion' => $this->qr->urlVerificacion($factura, $formato),
            // "Son: ..." de la representacion grafica: sobre lo que se paga.
            'montoLiteral' => $this->letras->bolivianos((float) $factura->monto_total - (float) $factura->gift_card),
            // Nombre de cada unidad de medida del catalogo del SIN; si no esta
            // sincronizado, la vista muestra el codigo.
            'unidades' => Catalogo::deTipo('unidades_medida')->pluck('descripcion', 'codigo_clasificador')->all(),
        ];
    }
}
