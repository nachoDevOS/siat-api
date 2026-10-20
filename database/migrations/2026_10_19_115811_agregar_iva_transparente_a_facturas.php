<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IVA Transparente (Ley 1733), los XSD V3 del piloto /sfd: los precios son
 * NETOS y el IVA (13%) se suma aparte. Las columnas que ya existian cambian
 * de sentido y se agregan las que faltaban:
 *
 *   - subtotal: subTotalNeto, la suma de los subTotal de los items (antes era
 *     el bruto, sin los descuentos de linea).
 *   - total_precio_neto: subTotalNeto - descuentoAdicional - giftCard.
 *   - iva: el 13% de total_precio_neto.
 *   - monto_total: montoaPagar = total_precio_neto + iva. Ya viene SIN la
 *     gift card: antes habia que restarla para saber lo cobrado.
 *   - factura_items.precio_unitario: precioNetoUnitario, sin IVA.
 *
 * En la nota de credito-debito las mismas columnas guardan lo DEVUELTO
 * (subTotalNetoDevuelto, totalPrecioNetoDevuelto, ivaDevuelto,
 * montoaPagarDevuelto); lo original se lee de la factura que ajusta.
 *
 * codigo_interno (empresas): el "Codigo Interno SIN" que el XSD V3 exige en la
 * cabecera. El SIN no explica que valor lleva; sus ejemplos mandan "A".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->decimal('total_precio_neto', 14, 2)->default(0)->after('gift_card');
            $table->decimal('iva', 14, 2)->default(0)->after('total_precio_neto');
        });

        Schema::table('empresas', function (Blueprint $table) {
            $table->string('codigo_interno', 10)->default('A')->after('codigo_modalidad');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropColumn('codigo_interno');
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn(['total_precio_neto', 'iva']);
        });
    }
};
