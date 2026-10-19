<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nota de credito-debito (documento sector 24): devolucion total o parcial de
 * una factura ya validada.
 *
 * La nota vive en la misma tabla que las facturas porque recorre el mismo
 * camino (CUF, XML firmado, envio, verificacion, anulacion, PDF, webhook);
 * lo que la distingue es codigo_documento_sector = 24 y estas columnas:
 *
 *   - factura_origen_id: la factura que ajusta. El XML de la nota repite su
 *     numero, CUF, fecha y monto, asi que se lee de ahi y no se copia.
 *   - monto_efectivo_credito_debito: el 13% del monto devuelto, el credito
 *     fiscal que se revierte.
 *   - codigo_detalle_transaccion (items): 1 = item de la factura original,
 *     2 = item devuelto. El XSD lleva las dos listas en el mismo <detalle>.
 *   - item_origen_id (items): la linea de la factura de la que sale un item
 *     devuelto. Con eso se sabe cuanto de cada linea ya se devolvio y no se
 *     puede devolver dos veces lo mismo en notas distintas.
 *   - siguiente_nota (puntos de venta): las notas tienen su propio
 *     correlativo, aparte del de facturas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->foreignId('factura_origen_id')->nullable()->after('cufd_id')
                ->constrained('facturas')->restrictOnDelete();
            $table->decimal('monto_efectivo_credito_debito', 14, 2)->nullable()->after('monto_total_sujeto_iva');
        });

        Schema::table('factura_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('codigo_detalle_transaccion')->nullable()->after('numero_imei');
            $table->foreignId('item_origen_id')->nullable()->after('codigo_detalle_transaccion')
                ->constrained('factura_items')->restrictOnDelete();
        });

        Schema::table('puntos_venta', function (Blueprint $table) {
            $table->unsignedBigInteger('siguiente_nota')->default(1)->after('siguiente_factura');
        });
    }

    public function down(): void
    {
        Schema::table('puntos_venta', function (Blueprint $table) {
            $table->dropColumn('siguiente_nota');
        });

        Schema::table('factura_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('item_origen_id');
            $table->dropColumn('codigo_detalle_transaccion');
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('factura_origen_id');
            $table->dropColumn('monto_efectivo_credito_debito');
        });
    }
};
