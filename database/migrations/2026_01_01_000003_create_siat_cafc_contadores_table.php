<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('siat_cafc_contadores', function (Blueprint $table) {
            $table->id();

            // El CAFC identifica el rango de numeración autorizado por el SIN para contingencia.
            // Se usa como clave para el contador porque un rango CAFC tiene inicio y fin propios.
            $table->string('cafc')->unique();

            // ultimo_numero se incrementa con lockForUpdate() para evitar duplicados concurrentes.
            $table->unsignedBigInteger('ultimo_numero')->default(0);

            // Rango autorizado por el SIN. fin = 0 significa sin límite superior.
            $table->unsignedBigInteger('inicio')->default(1);
            $table->unsignedBigInteger('fin')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('siat_cafc_contadores');
    }
};
