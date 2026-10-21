<?php

namespace App\Services\Factura;

use App\Services\Siat\SiatClient;

/**
 * Calcula los totales de una factura a partir de sus items y descuentos.
 * Es logica pura (sin base de datos ni SOAP) para poder probarla sola y para
 * que el resultado sea siempre el mismo dado el mismo insumo.
 *
 * IVA Transparente (Ley 1733, XSD V3): los precios son NETOS y el IVA se suma
 * al final. Las formulas salen del anexo tecnico del SIN y cuadran con su
 * XML de ejemplo (262.12 - 100 gift card - 20 descuento = 142.12 neto,
 * IVA 18.48, monto a pagar 160.60):
 *
 *     subTotal (item)  = cantidad * precioNetoUnitario - montoDescuento
 *     subTotalNeto     = suma de los subTotal
 *     totalPrecioNeto  = subTotalNeto - descuentoAdicional - montoGiftCard
 *     iva              = 13% de totalPrecioNeto
 *     montoaPagar      = totalPrecioNeto + iva
 *
 * Todos los montos se redondean a 2 decimales, como exige el SIN.
 *
 * Formato V2 (el anterior, que el piloto todavia pide en sus etapas
 * originales): el precio ya incluye el IVA, no se informa aparte, y el SIN
 * revalida con
 *
 *     montoTotal          = suma de los subTotal - descuentoAdicional
 *     montoTotalSujetoIva = montoTotal - montoGiftCard
 */
class CalculadorTotales
{
    /** Alicuota del IVA. */
    public const TASA_IVA = 0.13;

    /**
     * @param  list<array{cantidad: float|int, precio_unitario: float|int, descuento?: float|int}>  $items  precio_unitario NETO.
     * @param  float  $descuentoGlobal  descuento adicional sobre el subtotal neto.
     * @param  float  $giftCard  monto pagado con gift card (resta antes del IVA).
     * @return array{
     *     subtotal: float,
     *     descuento_items: float,
     *     descuento_global: float,
     *     gift_card: float,
     *     total_precio_neto: float,
     *     iva: float,
     *     monto_total: float,
     *     monto_total_sujeto_iva: float,
     *     items: list<array{subtotal: float}>
     * }
     */
    public function calcular(
        array $items,
        float $descuentoGlobal = 0.0,
        float $giftCard = 0.0,
        string $version = SiatClient::VERSION_V3,
    ): array {
        $subtotalNeto = 0.0;
        $descuentoItems = 0.0;
        $itemsCalculados = [];

        foreach ($items as $item) {
            $descuento = $this->redondear((float) ($item['descuento'] ?? 0.0));

            // El subtotal de la linea ya descuenta el descuento de esa linea.
            $subtotalLinea = $this->redondear(
                $this->redondear((float) $item['cantidad'] * (float) $item['precio_unitario']) - $descuento,
            );

            $subtotalNeto += $subtotalLinea;
            $descuentoItems += $descuento;

            $itemsCalculados[] = ['subtotal' => $subtotalLinea];
        }

        $subtotalNeto = $this->redondear($subtotalNeto);
        $descuentoGlobal = $this->redondear($descuentoGlobal);
        $giftCard = $this->redondear($giftCard);

        if ($version === SiatClient::VERSION_V2) {
            $montoTotal = $this->redondear($subtotalNeto - $descuentoGlobal);

            return [
                'subtotal' => $subtotalNeto,
                'descuento_items' => $this->redondear($descuentoItems),
                'descuento_global' => $descuentoGlobal,
                'gift_card' => $giftCard,
                // V2 no separa el IVA: va dentro del precio.
                'total_precio_neto' => $montoTotal,
                'iva' => 0.0,
                'monto_total' => $montoTotal,
                'monto_total_sujeto_iva' => max(0.0, $this->redondear($montoTotal - $giftCard)),
                'items' => $itemsCalculados,
            ];
        }

        $totalPrecioNeto = $this->redondear($subtotalNeto - $descuentoGlobal - $giftCard);
        $iva = $this->redondear($totalPrecioNeto * self::TASA_IVA);

        return [
            'subtotal' => $subtotalNeto,
            'descuento_items' => $this->redondear($descuentoItems),
            'descuento_global' => $descuentoGlobal,
            'gift_card' => $giftCard,
            'total_precio_neto' => $totalPrecioNeto,
            'iva' => $iva,
            'monto_total' => $this->redondear($totalPrecioNeto + $iva),
            'monto_total_sujeto_iva' => $totalPrecioNeto,
            'items' => $itemsCalculados,
        ];
    }

    private function redondear(float $monto): float
    {
        return round($monto, 2);
    }
}
