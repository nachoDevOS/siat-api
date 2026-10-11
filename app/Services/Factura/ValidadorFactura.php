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

            if (($item['precio_unitario'] ?? 0) < 0) {
                $errores[] = "Item {$linea}: el precio unitario no puede ser negativo.";
            }

            if (blank($item['descripcion'] ?? null)) {
                $errores[] = "Item {$linea}: falta la descripcion.";
            }

            // Un descuento mayor que la linea deja un subTotal negativo, que el
            // SIN observa. Mejor cortarlo aca, antes de reservar numero.
            $bruto = round((float) ($item['cantidad'] ?? 0) * (float) ($item['precio_unitario'] ?? 0), 2);
            $descuento = (float) ($item['descuento'] ?? 0);

            if ($descuento > $bruto) {
                $errores[] = "Item {$linea}: el descuento ({$descuento}) supera el importe de la linea ({$bruto}).";
            }

            $brutoTotal += $bruto;
            $descuentoItems += $descuento;
        }

        // Mismas cuentas que CalculadorTotales: el descuento global y la gift
        // card no pueden dejar la factura en negativo.
        $montoTotal = round($brutoTotal - $descuentoItems - (float) ($datos['descuento_global'] ?? 0), 2);

        if ($montoTotal < 0) {
            $errores[] = 'Los descuentos superan el importe de la venta: el monto total quedaria negativo.';
        }

        if ((float) ($datos['gift_card'] ?? 0) > max(0.0, $montoTotal)) {
            $errores[] = 'La gift card supera el monto total de la factura.';
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
