<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * codigoInterno que devuelve el SIN en cada CUFD por /sfd (respuestaCufd V3,
 * por ejemplo "C"). Es el que la factura emitida con ese CUFD tiene que llevar
 * en su cabecera: con otro, el SIN rechaza con [1077] CODIGO INTERNO NO VALIDO
 * "Codigo interno esperado C enviado A" (piloto, 20/10/2026). Null en los
 * pedidos por /v2, que no lo devuelve.
 *
 * Va en una migracion aparte para no tener que rehacer la base con el piloto
 * en curso; se puede pasar a create_codigos_table en la proxima consolidacion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cufd', function (Blueprint $table) {
            $table->string('codigo_interno', 10)->nullable()->after('codigo_control');
        });
    }

    public function down(): void
    {
        Schema::table('cufd', function (Blueprint $table) {
            $table->dropColumn('codigo_interno');
        });
    }
};
