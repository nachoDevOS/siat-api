<?php

namespace App\Services\Factura;

/**
 * Valida los datos de una factura ANTES de armar el XML y enviarla.
 * Atrapa errores obvios en local para no gastar un viaje al SIAT (que es lento)
 * en algo que ya sabemos que va a ser observado.
 *
 * Trabaja sobre el arreglo normalizado de la venta, no sobre el modelo, para
 * poder usarse apenas llega la peticion.
 */
class ValidadorFactura
{
    /** Tipo de documento de identidad NIT en el catalogo del SIN. */
    private const TIPO_DOCUMENTO_NIT = 5;

    /**
     * Devuelve la lista de errores encontrados. Vacia = la factura esta lista.
     *
     * @param  array<string, mixed>  $datos
     * @return list<string>
     */
    public function validar(array $datos): array
    {
        $errores = [];

        // Debe haber al menos un item.
        $items = $datos['items'] ?? [];

        if (! is_array($items) || count($items) === 0) {
            $errores[] = 'La factura debe tener al menos un item.';
        }

        // Bruto y descuentos acumulados para revisar los montos de la cabecera.
        $brutoTotal = 0.0;
        $descuentoItems = 0.0;

        foreach ($items as $i => $item) {
            $linea = $i + 1;

            if (($item['cantidad'] ?? 0) <= 0) {
                $errores[] = "Item {$linea}: la cantidad debe ser mayor a cero.";
            }

            // El XSD V3 exige precioNetoUnitario y subTotal mayores a cero.
            if (($item['precio_unitario'] ?? 0) <= 0) {
                $errores[] = "Item {$linea}: el precio unitario neto debe ser mayor a cero.";
            }

            if (blank($item['descripcion'] ?? null)) {
                $errores[] = "Item {$linea}: falta la descripcion.";
            }

            // Un descuento igual o mayor que la linea deja un subTotal en cero o
            // negativo, que el XSD V3 rechaza. Mejor cortarlo aca, antes de
            // reservar numero.
            $bruto = round((float) ($item['cantidad'] ?? 0) * (float) ($item['precio_unitario'] ?? 0), 2);
            $descuento = (float) ($item['descuento'] ?? 0);

            if ($descuento >= $bruto && $bruto > 0) {
                $errores[] = "Item {$linea}: el descuento ({$descuento}) deja la linea ({$bruto}) sin importe.";
            }

            $brutoTotal += $bruto;
            $descuentoItems += $descuento;
        }

        // Mismas cuentas que CalculadorTotales: el descuento global y la gift
        // card salen del neto, y el XSD V3 exige totalPrecioNeto mayor a cero.
        $neto = round($brutoTotal - $descuentoItems - (float) ($datos['descuento_global'] ?? 0), 2);

        if ($neto <= 0) {
            $errores[] = 'Los descuentos dejan la venta sin importe: el total neto debe ser mayor a cero.';
        } elseif ((float) ($datos['gift_card'] ?? 0) >= $neto) {
            $errores[] = 'La gift card cubre todo el neto de la factura: el total neto debe quedar mayor a cero.';
        }

        // El comprador es obligatorio.
        $comprador = $datos['comprador'] ?? [];

        if (blank($comprador['razon_social'] ?? null)) {
            $errores[] = 'Falta la razon social del comprador.';
        }

        if (blank($comprador['numero_documento'] ?? null)) {
            $errores[] = 'Falta el numero de documento del comprador.';
        }

        // La excepcion existe para el NIT que el SIN no reconoce. Con un CI o
        // pasaporte no hay nada que exceptuar.
        if ((int) ($datos['codigo_excepcion'] ?? 0) === 1
            && (int) ($comprador['tipo_documento'] ?? 0) !== self::TIPO_DOCUMENTO_NIT) {
            $errores[] = 'El codigo de excepcion solo aplica cuando el documento del comprador es NIT (tipo 5).';
        }

        return $errores;
    }
}
