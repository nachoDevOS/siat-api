<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formato de la factura: v3 (IVA Transparente, Ley 1733, por /sfd) o v2 (el
 * anterior, por /v2). El piloto exige facturas en las dos versiones (etapas
 * IV original y IV V.3) y cada una se arma, se envia, se verifica y se anula
 * por su propia ruta. Todo lo que ya existe es V3.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->string('version', 2)->default('v3')->after('codigo_documento_sector');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
