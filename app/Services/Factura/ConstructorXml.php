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
 * PENDIENTE DE VERIFICAR CONTRA EL XSD VIGENTE: los nombres de etiqueta y el
 * orden EXACTO en que van salen de la documentacion del SIN, no del esquema
 * real, y el SIN rechaza un documento con los elementos fuera de orden. Los
 * tipos declarados en el WSDL se pueden listar con:
 *
 *     php artisan siat:inspeccionar-wsdl {empresa} --servicio=compra_venta --tipos
 *
 * Si el XSD difiere, se ajusta solo este archivo: el resto del sistema no
 * depende de la forma del XML.
 */
class ConstructorXml
{
    /**
     * Documento de la modalidad ELECTRONICA EN LINEA. La modalidad
     * computarizada usa 'facturaComputarizadaCompraVenta'; el resto del
     * documento es igual salvo que la electronica va firmada.
     */
    private const RAIZ = 'facturaElectronicaCompraVenta';

    /**
     * Nota de credito-debito electronica (sector 24). Raiz y campos
     * contrastados con el SDK go-siat (notaFiscalElectronicaCreditoDebito.xsd).
     */
    private const RAIZ_NOTA = 'notaFiscalElectronicaCreditoDebito';

    private const NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    private const NS_XMLNS = 'http://www.w3.org/2000/xmlns/';

    /**
     * Devuelve el XML sin firmar de la factura. La firma se agrega despues
     * con FirmadorXml sobre este documento.
     */
    public function construir(Factura $factura): string
    {
        $factura->loadMissing(['items', 'empresa', 'puntoVenta.sucursal', 'facturaOrigen']);

        $esNota = $factura->esNotaCreditoDebito();
        $nombreRaiz = $esNota ? self::RAIZ_NOTA : self::RAIZ;

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = false;

        // Nodo raiz del documento fiscal, con la referencia al XSD que el SIN
        // usa para validarlo. Verificado contra un sistema en produccion: sin
        // estos dos atributos el documento no valida del otro lado.
        $raiz = $doc->createElement($nombreRaiz);
        // La declaracion del prefijo va una sola vez en la raiz; si se usara
        // setAttribute suelto, DOM la repetiria en cada hijo con xsi:nil.
        $raiz->setAttributeNS(self::NS_XMLNS, 'xmlns:xsi', self::NS_XSI);
        $raiz->setAttributeNS(self::NS_XSI, 'xsi:noNamespaceSchemaLocation', $nombreRaiz.'.xsd');
        $doc->appendChild($raiz);

        if ($esNota) {
            $this->cabeceraNota($doc, $raiz, $factura);
        } else {
            $this->cabecera($doc, $raiz, $factura);
        }

        // Un nodo <detalle> por cada item. En la nota van primero los de la
        // factura original y despues los devueltos, en el orden en que se
        // guardaron.
        foreach ($factura->items as $item) {
            $esNota ? $this->detalleNota($doc, $raiz, $item) : $this->detalle($doc, $raiz, $item);
        }

        return $doc->saveXML();
    }

    private function cabecera(DOMDocument $doc, \DOMElement $raiz, Factura $factura): void
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
            // 0 normal, 1 = emitir aunque el NIT no valide. Va siempre con
            // valor y no nil, igual que el sistema que factura en produccion.
            'codigoExcepcion' => $factura->codigo_excepcion ?? 0,
            // Siempre nil: el CAFC es de la modalidad computarizada y este
            // sistema solo emite electronica en linea. El elemento igual va,
            // porque el XSD lo declara en la secuencia de la cabecera.
            'cafc' => null,
            'leyenda' => $factura->leyenda,
            'usuario' => $factura->usuario,
            'codigoDocumentoSector' => $factura->codigo_documento_sector,
        ];

        $this->nodoDesdeArray($doc, $raiz, 'cabecera', $campos);
    }

    private function detalle(DOMDocument $doc, \DOMElement $raiz, FacturaItem $item): void
    {
        $campos = [
            // La resuelve ResolutorActividad al emitir y queda guardada en el
            // item: aca solo se lee, para que el XML sea siempre el mismo por
            // mas que el SIN reasigne el producto a otra actividad despues.
            'actividadEconomica' => $item->codigo_actividad,
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

        $this->nodoDesdeArray($doc, $raiz, 'detalle', $campos);
    }

    /**
     * Cabecera de la nota de credito-debito. Repite los datos del emisor y
     * del comprador como una factura, y agrega la referencia a la factura
     * original (numero, CUF y fecha) y los montos del ajuste.
     *
     * Orden de notaFiscalElectronicaCreditoDebito.xsd (contrastado con
     * go-siat): a diferencia de la factura no lleva metodo de pago, moneda,
     * gift card ni cafc.
     */
    private function cabeceraNota(DOMDocument $doc, \DOMElement $raiz, Factura $nota): void
    {
        $empresa = $nota->empresa;
        $sucursal = $nota->puntoVenta->sucursal;
        $origen = $nota->facturaOrigen;

        $campos = [
            'nitEmisor' => $empresa->nit,
            'razonSocialEmisor' => $empresa->razon_social,
            'municipio' => $sucursal->municipio,
            'telefono' => $sucursal->telefono,
            'numeroNotaCreditoDebito' => $nota->numero_factura,
            'cuf' => $nota->cuf,
            'cufd' => optional($nota->cufd)->codigo,
            'codigoSucursal' => $sucursal->codigo_sucursal,
            'direccion' => $sucursal->direccion,
            'codigoPuntoVenta' => $nota->puntoVenta->codigo_punto_venta,
            'fechaEmision' => $nota->fecha_emision?->format('Y-m-d\TH:i:s.v'),
            'nombreRazonSocial' => $nota->comprador_razon_social,
            'codigoTipoDocumentoIdentidad' => $nota->comprador_tipo_documento,
            'numeroDocumento' => $nota->comprador_numero_documento,
            'complemento' => $nota->comprador_complemento,
            'codigoCliente' => $nota->comprador_numero_documento,
            // La factura que se ajusta: su numero, su CUF (el SIN lo llama
            // numero de autorizacion) y su fecha de emision.
            'numeroFactura' => $origen->numero_factura,
            'numeroAutorizacionCuf' => $origen->cuf,
            'fechaEmisionFactura' => $origen->fecha_emision?->format('Y-m-d\TH:i:s.v'),
            'montoTotalOriginal' => $this->monto($origen->monto_total),
            // subtotal de la nota = suma de los items devueltos.
            'montoTotalDevuelto' => $this->monto($nota->subtotal),
            // La parte del descuento adicional de la factura que corresponde a
            // lo devuelto (descuento_global de la nota).
            'montoDescuentoCreditoDebito' => $this->monto($nota->descuento_global),
            'montoEfectivoCreditoDebito' => $this->monto($nota->monto_efectivo_credito_debito),
            'codigoExcepcion' => $nota->codigo_excepcion ?? 0,
            'leyenda' => $nota->leyenda,
            'usuario' => $nota->usuario,
            'codigoDocumentoSector' => $nota->codigo_documento_sector,
        ];

        $this->nodoDesdeArray($doc, $raiz, 'cabecera', $campos);
    }

    /**
     * Detalle de la nota: como el de la factura, sin numero de serie ni IMEI,
     * y con codigoDetalleTransaccion (1 = item original, 2 = devuelto).
     */
    private function detalleNota(DOMDocument $doc, \DOMElement $raiz, FacturaItem $item): void
    {
        $campos = [
            'actividadEconomica' => $item->codigo_actividad,
            'codigoProductoSin' => $item->codigo_producto_sin,
            'codigoProducto' => $item->codigo_interno,
            'descripcion' => $item->descripcion,
            'cantidad' => $this->monto($item->cantidad, 4),
            'unidadMedida' => $item->unidad_medida,
            'precioUnitario' => $this->monto($item->precio_unitario),
            'montoDescuento' => $this->monto($item->descuento),
            'subTotal' => $this->monto($item->subtotal),
            'codigoDetalleTransaccion' => $item->codigo_detalle_transaccion,
        ];

        $this->nodoDesdeArray($doc, $raiz, 'detalle', $campos);
    }

    /**
     * Crea un nodo con hijos a partir de un arreglo campo => valor.
     * Un campo vacio NO se omite: se escribe el elemento con xsi:nil="true".
     *
     * Antes se saltaban los nulos, y eso rompe la validacion: el XSD del SIN
     * declara una secuencia de elementos, asi que faltar uno corre a todos los
     * demas de lugar. Verificado contra un sistema en produccion.
     *
     * @param  array<string, mixed>  $campos
     */
    private function nodoDesdeArray(DOMDocument $doc, \DOMElement $padre, string $nombre, array $campos): void
    {
        // El nodo se engancha al arbol ANTES de llenarlo para que los hijos con
        // xsi:nil hereden la declaracion del prefijo de la raiz en vez de
        // repetirla cada uno.
        $nodo = $padre->appendChild($doc->createElement($nombre));

        foreach ($campos as $etiqueta => $valor) {
            $hijo = $nodo->appendChild($doc->createElement($etiqueta));

            // El cero es un valor, no un vacio: montoGiftCard = 0 debe viajar.
            if ($valor === null || $valor === '') {
                $hijo->setAttributeNS(self::NS_XSI, 'xsi:nil', 'true');

                continue;
            }

            // createElement no escapa el contenido; usamos un nodo de texto
            // para que caracteres como & o < queden bien codificados.
            $hijo->appendChild($doc->createTextNode((string) $valor));
        }
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
