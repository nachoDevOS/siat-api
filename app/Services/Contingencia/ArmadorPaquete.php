<?php

namespace App\Services\Contingencia;

use App\Models\Factura;
use App\Models\Paquete;

/**
 * Arma el contenido de un paquete de contingencia: agrupa los XML firmados de
 * las facturas del evento en un solo documento para enviarlo con
 * recepcionPaqueteFactura.
 *
 * OJO: el formato exacto del envoltorio del paquete (raiz y etiquetas) debe
 * confirmarse contra el XSD de paquete vigente del SIN (rule 7).
 */
class ArmadorPaquete
{
    /**
     * Devuelve el XML del paquete con todas las facturas de contingencia del
     * evento asociado.
     */
    public function armar(Paquete $paquete): string
    {
        // Se toman SOLO las facturas asignadas a este paquete. Filtrar por
        // punto de venta + estado incluiria facturas que entraron a
        // contingencia despues de armarlo.
        $facturas = Factura::where('paquete_id', $paquete->id)
            ->whereNotNull('xml_firmado')
            ->orderBy('numero_factura')
            ->get();

        // Envoltorio simple: una raiz con cada factura firmada dentro.
        $cuerpo = $facturas
            ->map(fn (Factura $f) => $this->sinDeclaracionXml((string) $f->xml_firmado))
            ->implode('');

        return '<?xml version="1.0" encoding="UTF-8"?><paquete>'.$cuerpo.'</paquete>';
    }

    /**
     * Quita la declaracion <?xml ...?> de un XML individual para poder anidarlo
     * dentro del paquete sin declaraciones repetidas.
     */
    private function sinDeclaracionXml(string $xml): string
    {
        return preg_replace('/<\?xml.*?\?>/', '', $xml, 1) ?? $xml;
    }
}
