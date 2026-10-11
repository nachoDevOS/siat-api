<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La factura de compra-venta (sector 1) no tiene anticipo: el XSD no lo
 * declara y la API lo rechaza desde 2026-10-11. La columna quedo siempre en
 * cero, asi que se quita en vez de dejar un dato que no significa nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn('anticipo');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->decimal('anticipo', 14, 2)->default(0)->after('gift_card');
        });
    }
};
