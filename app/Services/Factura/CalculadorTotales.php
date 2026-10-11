<?php

namespace App\Services\Factura;

/**
 * Calcula los totales de una factura a partir de sus items y descuentos.
 * Es logica pura (sin base de datos ni SOAP) para poder probarla sola y para
 * que el resultado sea siempre el mismo dado el mismo insumo.
 *
 * Todos los montos se redondean a 2 decimales, como exige el SIN.
 */
class CalculadorTotales
{
    /**
     * @param  list<array{cantidad: float|int, precio_unitario: float|int, descuento?: float|int}>  $items
     * @param  float  $descuentoGlobal  descuento aplicado al total de la factura.
     * @param  float  $giftCard  monto pagado con gift card (resta al total a pagar).
     * @return array{
     *     subtotal: float,
     *     descuento_items: float,
     *     descuento_global: float,
     *     gift_card: float,
     *     monto_total: float,
     *     monto_total_sujeto_iva: float,
     *     items: list<array{subtotal: float}>
     * }
     */
    public function calcular(
        array $items,
        float $descuentoGlobal = 0.0,
        float $giftCard = 0.0,
    ): array {
        $subtotalBruto = 0.0;   // suma de cantidad * precio, sin descuentos
        $descuentoItems = 0.0;  // suma de descuentos por linea
        $itemsCalculados = [];

        foreach ($items as $item) {
            $cantidad = (float) $item['cantidad'];
            $precio = (float) $item['precio_unitario'];
            $descuento = (float) ($item['descuento'] ?? 0.0);

            $bruto = $this->redondear($cantidad * $precio);
            // El subtotal de la linea ya descuenta el descuento de esa linea.
            $subtotalLinea = $this->redondear($bruto - $descuento);

            $subtotalBruto += $bruto;
            $descuentoItems += $descuento;

            $itemsCalculados[] = ['subtotal' => $subtotalLinea];
        }

        $subtotalBruto = $this->redondear($subtotalBruto);
        $descuentoItems = $this->redondear($descuentoItems);
        $descuentoGlobal = $this->redondear($descuentoGlobal);

        // Monto total = bruto - descuentos de linea - descuento global.
        $montoTotal = $this->redondear($subtotalBruto - $descuentoItems - $descuentoGlobal);

        // Sujeto a IVA = monto total - gift card: es la regla con la que el SIN
        // revalida el sector 1 (compra-venta). Ese documento NO tiene campo de
        // anticipo; restarlo aca daba un sujeto a IVA que no cuadra con los
        // montos del XML y el SIN observaba la factura.
        $sujetoIva = $this->redondear($montoTotal - $this->redondear($giftCard));

        return [
            'subtotal' => $subtotalBruto,
            'descuento_items' => $descuentoItems,
            'descuento_global' => $descuentoGlobal,
            'gift_card' => $this->redondear($giftCard),
            'monto_total' => $montoTotal,
            'monto_total_sujeto_iva' => max(0.0, $sujetoIva),
            'items' => $itemsCalculados,
        ];
    }

    private function redondear(float $monto): float
    {
        return round($monto, 2);
    }
}
