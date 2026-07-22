<?php

namespace App\Services\Factura;

use App\Models\Factura;
use App\Models\FacturaItem;
use DOMDocument;

/**
 * Arma el XML de la factura de compra-venta a partir del modelo Factura.
 *
 * La estructura sigue el esquema "facturaElectronicaCompraVenta" del SIN:
 * una cabecera con los datos generales y un detalle por cada item.
 *
 * OJO: los nombres de etiquetas y el orden EXACTO deben validarse contra el
 * XSD vigente que publica el SIN (rule 7). Si el XSD cambia, se ajusta solo
 * este archivo; el resto del sistema no depende de la forma del XML.
 */
class ConstructorXml
{
    /**
     * Devuelve el XML sin firmar de la factura. La firma se agrega despues
     * con FirmadorXml sobre este documento.
     */
    public function construir(Factura $factura): string
    {
        $factura->loadMissing(['items', 'empresa', 'puntoVenta.sucursal']);

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = false;

        // Nodo raiz del documento fiscal.
        $raiz = $doc->createElement('facturaElectronicaCompraVenta');
        $doc->appendChild($raiz);

        $raiz->appendChild($this->cabecera($doc, $factura));

        // Un nodo <detalle> por cada item de la factura.
        foreach ($factura->items as $item) {
            $raiz->appendChild($this->detalle($doc, $item));
        }

        return $doc->saveXML();
    }

    private function cabecera(DOMDocument $doc, Factura $factura): \DOMElement
    {
        $empresa = $factura->empresa;
        $sucursal = $factura->puntoVenta->sucursal;

        // Orden y nombres tentativos segun el manual del SIN; verificar XSD.
        $campos = [
            'nitEmisor' => $empresa->nit,
            'razonSocialEmisor' => $empresa->razon_social,
            'municipio' => $sucursal->municipio,
            'telefono' => $sucursal->telefono,
            'numeroFactura' => $factura->numero_factura,
            'cuf' => $factura->cuf,
            'cufd' => optional($factura->cufd)->codigo,
            'codigoSucursal' => $sucursal->codigo_sucursal,
            'direccion' => $sucursal->direccion,
            'codigoPuntoVenta' => $factura->puntoVenta->codigo_punto_venta,
            'fechaEmision' => $factura->fecha_emision?->format('Y-m-d\TH:i:s.v'),
            'nombreRazonSocial' => $factura->comprador_razon_social,
            'codigoTipoDocumentoIdentidad' => $factura->comprador_tipo_documento,
            'numeroDocumento' => $factura->comprador_numero_documento,
            'complemento' => $factura->comprador_complemento,
            'codigoCliente' => $factura->comprador_numero_documento,
            'codigoMetodoPago' => $factura->metodo_pago,
            'numeroTarjeta' => $factura->numero_tarjeta,
            'montoTotal' => $this->monto($factura->monto_total),
            'montoTotalSujetoIva' => $this->monto($factura->monto_total_sujeto_iva),
            'codigoMoneda' => $factura->moneda,
            'tipoCambio' => $this->monto($factura->tipo_cambio),
            'montoTotalMoneda' => $this->monto($factura->monto_total_moneda),
            'montoGiftCard' => $this->monto($factura->gift_card),
            'descuentoAdicional' => $this->monto($factura->descuento_global),
            'codigoExcepcion' => null,
            'cafc' => optional($factura->cafc)->codigo,
            'leyenda' => $factura->leyenda,
            'usuario' => $factura->usuario,
            'codigoDocumentoSector' => $factura->codigo_documento_sector,
        ];

        return $this->nodoDesdeArray($doc, 'cabecera', $campos);
    }

    private function detalle(DOMDocument $doc, FacturaItem $item): \DOMElement
    {
        $campos = [
            'actividadEconomica' => null, // se completa desde la actividad del NIT
            'codigoProductoSin' => $item->codigo_producto_sin,
            'codigoProducto' => $item->codigo_interno,
            'descripcion' => $item->descripcion,
            'cantidad' => $this->monto($item->cantidad, 4),
            'unidadMedida' => $item->unidad_medida,
            'precioUnitario' => $this->monto($item->precio_unitario),
            'montoDescuento' => $this->monto($item->descuento),
            'subTotal' => $this->monto($item->subtotal),
            'numeroSerie' => $item->numero_serie,
            'numeroImei' => $item->numero_imei,
        ];

        return $this->nodoDesdeArray($doc, 'detalle', $campos);
    }

    /**
     * Crea un nodo con hijos a partir de un arreglo campo => valor.
     * Los valores null se omiten (el XSD del SIN trata los opcionales asi).
     *
     * @param  array<string, mixed>  $campos
     */
    private function nodoDesdeArray(DOMDocument $doc, string $nombre, array $campos): \DOMElement
    {
        $nodo = $doc->createElement($nombre);

        foreach ($campos as $etiqueta => $valor) {
            if ($valor === null || $valor === '') {
                continue;
            }

            // createElement no escapa el contenido; usamos un nodo de texto
            // para que caracteres como & o < queden bien codificados.
            $hijo = $doc->createElement($etiqueta);
            $hijo->appendChild($doc->createTextNode((string) $valor));
            $nodo->appendChild($hijo);
        }

        return $nodo;
    }

    /**
     * Formatea un monto con la cantidad de decimales que pide el SIN (2 por
     * defecto), con punto como separador y sin separador de miles.
     */
    private function monto(int|float|string|null $valor, int $decimales = 2): string
    {
        return number_format((float) $valor, $decimales, '.', '');
    }
}
