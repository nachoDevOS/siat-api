<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El CUF identifica de forma univoca a una factura ante el SIN, pero la tabla
 * solo tenia un indice normal: nada impedia guardar dos facturas con el mismo
 * CUF si un reintento o una carrera lo recalculaba igual.
 *
 * La unicidad se pide a la base y no solo al codigo PHP porque dos peticiones
 * concurrentes pueden pasar cualquier chequeo previo en memoria; solo una gana
 * el INSERT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropIndex('facturas_cuf_index');
            $table->unique('cuf');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropUnique('facturas_cuf_unique');
            $table->index('cuf');
        });
    }
};
