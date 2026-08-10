<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registrar un punto de venta en el SIAT es IRREVERSIBLE: el SIN asigna un
 * codigo nuevo cada vez que se llama a registroPuntoVenta, y un punto de venta
 * cerrado no se vuelve a abrir.
 *
 * Sin esta marca, el paso 10 del piloto creaba un punto de venta nuevo en cada
 * clic: dos reintentos dejaron dos "PRINCIPAL" duplicados del lado del SIN.
 *
 * Ademas el codigo local no tenia por que coincidir con el que asigna el SIN
 * (local arrancaba en 0 y el SIN devolvia 9, 10, ...), asi que se emitia con un
 * codigo de punto de venta que el SIN no reconoce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puntos_venta', function (Blueprint $table) {
            // Cuando se registro ante el SIN. Null = todavia es solo local.
            $table->timestamp('registrado_en_siat')->nullable()->after('activo');
        });
    }

    public function down(): void
    {
        Schema::table('puntos_venta', function (Blueprint $table) {
            $table->dropColumn('registrado_en_siat');
        });
    }
};
