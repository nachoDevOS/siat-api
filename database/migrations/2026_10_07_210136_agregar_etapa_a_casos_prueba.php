<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrupa los casos por las ETAPAS del portal del SIN.
 *
 * El portal (Seguimiento de Autorizacion de Sistemas) no lista pasos sueltos:
 * lista etapas, y cada prueba de una etapa tiene sus parametros (sucursal,
 * punto de venta) y una cantidad de "pruebas esperadas". Sin estas dos
 * columnas no habia como reflejar ni una cosa ni la otra.
 *
 * 'etapa' es nullable: los pasos viejos de la secuencia quedan sin etapa
 * hasta que se pasen, etapa por etapa, al formato del portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('casos_prueba', function (Blueprint $table) {
            $table->unsignedTinyInteger('etapa')->nullable()->after('fase');
            $table->unsignedInteger('pruebas_esperadas')->default(1)->after('obligatorio');
        });
    }

    public function down(): void
    {
        Schema::table('casos_prueba', function (Blueprint $table) {
            $table->dropColumn(['etapa', 'pruebas_esperadas']);
        });
    }
};
