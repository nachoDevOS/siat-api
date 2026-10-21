<?php

namespace App\Services\Factura;

use App\Models\Factura;
use App\Models\FacturaItem;
use App\Services\Siat\SiatClient;
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

    /**
     * Elementos obligatorios que el XSD NO declara nillable: vacios van como
     * elemento vacio (<complemento/>), sin xsi:nil. Verificado en el piloto
     * /sfd (V3, 19/10/2026), las dos formas vuelven con [920]:
     *  - con xsi:nil: "Attribute xsi:nil must not appear on element
     *    'complemento', because the {nillable} property ... is false";
     *  - omitido: "Invalid content found starting with 'codigoCliente'. One
     *    of '{complemento}' is expected".
     * Si el SIN rechaza otro campo igual, va aca.
     *
     * @var list<string>
     */
    private const VACIO_SIN_NIL = ['complemento'];

    /**
     * Complemento cuando el comprador no tiene: el SIN V3 tampoco acepta el
     * elemento vacio, responde [1068] COMPLEMENTO NO VALIDO "Complemento
     * esperado 0 enviado " (piloto /sfd, 20/10/2026, con CI). Visto con CI;
     * con otros tipos de documento falta confirmarlo.
     */
    private const SIN_COMPLEMENTO = '0';

    private const NS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';

    private const NS_XMLNS = 'http://www.w3.org/2000/xmlns/';

    /**
     * Devuelve el XML sin firmar de la factura. La firma se agrega despues
     * con FirmadorXml sobre este documento.
     */
    public function construir(Factura $factura): string
    {
        $factura->loadMissing(['items', 'empresa', 'puntoVenta.sucursal', 'facturaOrigen.items']);

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

        $esV2 = ! $esNota && $factura->version === SiatClient::VERSION_V2;

        if ($esNota) {
            $this->cabeceraNota($doc, $raiz, $factura);
        } elseif ($esV2) {
            $this->cabeceraV2($doc, $raiz, $factura);
        } else {
            $this->cabecera($doc, $raiz, $factura);
        }

        // Un nodo <detalle> por cada item. En la nota van primero los de la
        // factura original y despues los devueltos, en el orden en que se
        // guardaron.
        $nroItem = $esNota ? $this->numerosDeItem($factura) : [];

        foreach ($factura->items as $item) {
            match (true) {
                $esNota => $this->detalleNota($doc, $raiz, $item, $nroItem[$item->item_origen_id] ?? null),
                $esV2 => $this->detalleV2($doc, $raiz, $item),
                default => $this->detalle($doc, $raiz, $item),
            };
        }

        return $doc->saveXML();
    }

    /**
     * Cabecera en el formato V2 (antes de la Ley 1733), el que piden las
     * etapas originales del piloto por /v2. Es la que armaba este sistema
     * hasta el 12/10/2026 (commit 769f83a): precio con IVA incluido y
     * montoTotal/montoTotalSujetoIva en lugar de los montos netos e iva; sin
     * codigoInterno; con cafc (nil: es de la computarizada); el complemento
     * vacio va en xsi:nil, como lo manda el sistema que factura en
     * produccion en V2.
     */
    private function cabeceraV2(DOMDocument $doc, \DOMElement $raiz, Factura $factura): void
    {
        $empresa = $factura->empresa;
        $sucursal = $factura->puntoVenta->sucursal;

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
            'codigoExcepcion' => $factura->codigo_excepcion ?? 0,
            'cafc' => null,
            'leyenda' => $factura->leyenda,
            'usuario' => $factura->usuario,
            'codigoDocumentoSector' => $factura->codigo_documento_sector,
        ];

        $this->nodoDesdeArray($doc, $raiz, 'cabecera', $campos, vaciosSinNil: []);
    }

    /**
     * Detalle V2: precioUnitario (con IVA) en lugar de precioNetoUnitario, y la
     * cantidad con 4 decimales.
     */
    private function detalleV2(DOMDocument $doc, \DOMElement $raiz, FacturaItem $item): void
    {
        $this->nodoDesdeArray($doc, $raiz, 'detalle', [
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
        ], vaciosSinNil: []);
    }

    private function cabecera(DOMDocument $doc, \DOMElement $raiz, Factura $factura): void
    {
        $empresa = $factura->empresa;
        $sucursal = $factura->puntoVenta->sucursal;

        // Orden y nombres del XSD V3 (IVA Transparente) de compra-venta, del
        // anexo tecnico de la Ley 1733: siatanexo.impuestos.gob.bo, articulo
        // 231, CompraVentaXML.zip. El de siatinfo sigue siendo el V2.
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
            'complemento' => filled($factura->comprador_complemento) ? $factura->comprador_complemento : self::SIN_COMPLEMENTO,
            'codigoCliente' => $factura->comprador_numero_documento,
            'codigoMetodoPago' => $factura->metodo_pago,
            'numeroTarjeta' => $factura->numero_tarjeta,
            // Montos V3: ver CalculadorTotales. Reemplazan a montoTotal y
            // montoTotalSujetoIva del V2.
            'subTotalNeto' => $this->monto($factura->subtotal),
            'totalPrecioNeto' => $this->monto($factura->total_precio_neto),
            'iva' => $this->monto($factura->iva),
            'montoaPagar' => $this->monto($factura->monto_total),
            'codigoMoneda' => $factura->moneda,
            'tipoCambio' => $this->monto($factura->tipo_cambio),
            'montoTotalMoneda' => $this->monto($factura->monto_total_moneda),
            'montoGiftCard' => $this->monto($factura->gift_card),
            'descuentoAdicional' => $this->monto($factura->descuento_global),
            // 0 normal, 1 = emitir aunque el NIT no valide. Va siempre con
            // valor y no nil, igual que el sistema que factura en produccion.
            // En V3 va despues de los montos y el cafc ya no existe.
            'codigoExcepcion' => $factura->codigo_excepcion ?? 0,
            'leyenda' => $factura->leyenda,
            'usuario' => $factura->usuario,
            'codigoInterno' => $factura->codigoInterno(),
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
            // El XSD V3 admite 2 decimales en la cantidad (el V2, 4).
            'cantidad' => $this->monto($item->cantidad),
            'unidadMedida' => $item->unidad_medida,
            'precioNetoUnitario' => $this->monto($item->precio_unitario),
            'montoDescuento' => $this->monto($item->descuento),
            'subTotal' => $this->monto($item->subtotal),
            'numeroSerie' => $item->numero_serie,
            'numeroImei' => $item->numero_imei,
        ];

        $this->nodoDesdeArray($doc, $raiz, 'detalle', $campos);
    }

    /**
     * nroItem de la nota: la posicion (desde 1) de cada linea en la factura
     * original, indexada por id de item. Un item devuelto lleva el numero de
     * la linea de la que sale, como en el XML de ejemplo del SIN.
     *
     * @return array<int, int>
     */
    private function numerosDeItem(Factura $nota): array
    {
        return $nota->facturaOrigen->items
            ->sortBy('id')
            ->values()
            ->mapWithKeys(fn (FacturaItem $item, int $i): array => [$item->id => $i + 1])
            ->all();
    }

    /**
     * Cabecera de la nota de credito-debito. Repite los datos del emisor y
     * del comprador como una factura, y agrega la referencia a la factura
     * original (numero, CUF y fecha) y los montos del ajuste.
     *
     * Orden del XSD V3 (IVA Transparente) notaElectronicaCreditoDebito, del
     * anexo tecnico de la Ley 1733 (articulo 285, CreditoDebitoXML.zip): a
     * diferencia de la factura no lleva metodo de pago, moneda ni gift card,
     * y repite los montos de la factura (...Original) y los de lo devuelto.
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
            'complemento' => filled($nota->comprador_complemento) ? $nota->comprador_complemento : self::SIN_COMPLEMENTO,
            'codigoCliente' => $nota->comprador_numero_documento,
            // La factura que se ajusta: su numero, su CUF (el SIN lo llama
            // numero de autorizacion) y su fecha de emision.
            'numeroFactura' => $origen->numero_factura,
            'numeroAutorizacionCuf' => $origen->cuf,
            'fechaEmisionFactura' => $origen->fecha_emision?->format('Y-m-d\TH:i:s.v'),
            // Los montos de la factura que se ajusta, tal como los emitio.
            'subTotalNetoOriginal' => $this->monto($origen->subtotal),
            'totalPrecioNetoOriginal' => $this->monto($origen->total_precio_neto),
            'ivaOriginal' => $this->monto($origen->iva),
            'montoaPagarOriginal' => $this->monto($origen->monto_total),
            // Los de lo devuelto: ver EmisorNotaCreditoDebito::montos().
            'subTotalNetoDevuelto' => $this->monto($nota->subtotal),
            'totalPrecioNetoDevuelto' => $this->monto($nota->total_precio_neto),
            'ivaDevuelto' => $this->monto($nota->iva),
            'montoaPagarDevuelto' => $this->monto($nota->monto_total),
            // PENDIENTE DE VERIFICAR: el XSD V3 publicado declara este y
            // montoDescuentoDevuelto con minExclusive 0, sin nil, asi que una
            // factura sin descuento adicional (0.00) no validaria contra el.
            // Parece un error del esquema; se manda 0.00 hasta ver que acepta
            // el piloto.
            'descuentoAdicionalOriginal' => $this->monto($origen->descuento_global),
            // La parte del descuento adicional de la factura que corresponde a
            // lo devuelto (descuento_global de la nota).
            'montoDescuentoDevuelto' => $this->monto($nota->descuento_global),
            'codigoExcepcion' => $nota->codigo_excepcion ?? 0,
            'leyenda' => $nota->leyenda,
            'usuario' => $nota->usuario,
            'codigoInterno' => $nota->codigoInterno(),
            'codigoDocumentoSector' => $nota->codigo_documento_sector,
        ];

        $this->nodoDesdeArray($doc, $raiz, 'cabecera', $campos);
    }

    /**
     * Detalle de la nota: como el de la factura, sin numero de serie ni IMEI,
     * con nroItem al principio (V3) y codigoDetalleTransaccion al final
     * (1 = item original, 2 = devuelto).
     */
    private function detalleNota(DOMDocument $doc, \DOMElement $raiz, FacturaItem $item, ?int $nroItem): void
    {
        $campos = [
            'nroItem' => $nroItem,
            'actividadEconomica' => $item->codigo_actividad,
            'codigoProductoSin' => $item->codigo_producto_sin,
            'codigoProducto' => $item->codigo_interno,
            'descripcion' => $item->descripcion,
            'cantidad' => $this->monto($item->cantidad),
            'unidadMedida' => $item->unidad_medida,
            'precioNetoUnitario' => $this->monto($item->precio_unitario),
            'montoDescuento' => $this->monto($item->descuento),
            'subTotal' => $this->monto($item->subtotal),
            'codigoDetalleTransaccion' => $item->codigo_detalle_transaccion,
        ];

        $this->nodoDesdeArray($doc, $raiz, 'detalle', $campos);
    }

    /**
     * Crea un nodo con hijos a partir de un arreglo campo => valor.
     * Un campo vacio NO se omite: se escribe el elemento con xsi:nil="true".
     * La excepcion son los de VACIO_SIN_NIL: van vacios, sin el atributo.
     *
     * Antes se saltaban los nulos, y eso rompe la validacion: el XSD del SIN
     * declara una secuencia de elementos, asi que faltar uno corre a todos los
     * demas de lugar. Verificado contra un sistema en produccion.
     *
     * @param  array<string, mixed>  $campos
     * @param  list<string>  $vaciosSinNil  campos que vacios van como elemento
     *                                      vacio y no en xsi:nil (ver VACIO_SIN_NIL).
     */
    private function nodoDesdeArray(DOMDocument $doc, \DOMElement $padre, string $nombre, array $campos, array $vaciosSinNil = self::VACIO_SIN_NIL): void
    {
        // El nodo se engancha al arbol ANTES de llenarlo para que los hijos con
        // xsi:nil hereden la declaracion del prefijo de la raiz en vez de
        // repetirla cada uno.
        $nodo = $padre->appendChild($doc->createElement($nombre));

        foreach ($campos as $etiqueta => $valor) {
            // El cero es un valor, no un vacio: montoGiftCard = 0 debe viajar.
            $vacio = $valor === null || $valor === '';

            $hijo = $nodo->appendChild($doc->createElement($etiqueta));

            if ($vacio && in_array($etiqueta, $vaciosSinNil, true)) {
                continue;
            }

            if ($vacio) {
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
