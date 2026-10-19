<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Solicitudes que deja un interesado en el formulario del sitio publico. Las
 * atiende el administrador desde el panel (/admin/solicitudes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('solicitudes_contacto', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('empresa')->nullable();
            $table->string('correo');
            $table->string('telefono')->nullable();
            // Que busca: integrar su sistema, facturar sin sistema propio, otro.
            $table->string('interes');
            $table->text('mensaje');
            // Para reconocer envios repetidos o abusivos; no se muestra en el sitio.
            $table->string('ip', 45)->nullable();
            $table->dateTime('atendida_en')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('solicitudes_contacto');
    }
};
