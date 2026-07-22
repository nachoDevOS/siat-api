<?php

namespace Database\Factories;

use App\Models\Certificado;
use App\Models\Empresa;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Certificado>
 */
class CertificadoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empresa_id' => Empresa::factory(),
            // Contenido de relleno; el .p12 real se carga en el panel.
            'contenido_p12' => base64_encode(Str::random(64)),
            'passphrase' => Str::random(12),
            'emitido_por' => 'Entidad Certificadora de Prueba',
            'vence_el' => now()->addYear(),
            'activo' => true,
        ];
    }
}
