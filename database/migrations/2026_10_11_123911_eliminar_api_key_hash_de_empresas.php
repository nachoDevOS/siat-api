<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La API pasa de una API key unica por empresa (X-Api-Key, hash en esta
 * columna) a tokens de Sanctum: varios por empresa, con permisos, vencimiento
 * y revocacion (tabla personal_access_tokens).
 *
 * Se elimina sin periodo de convivencia porque ningun cliente factura todavia
 * en produccion: mantener dos mecanismos de autenticacion solo duplicaba la
 * superficie de ataque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->dropUnique(['api_key_hash']);
            $table->dropColumn('api_key_hash');
        });
    }

    public function down(): void
    {
        Schema::table('empresas', function (Blueprint $table) {
            $table->string('api_key_hash', 64)->nullable()->unique()->after('token_delegado');
        });
    }
};
