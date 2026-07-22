<?php

namespace App\Models;

use Database\Factories\CasoPruebaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Caso de prueba ante el SIN. Vive en base de datos (no en codigo) para poder
 * editarlo cuando el SIN cambie el manual de pruebas.
 */
class CasoPrueba extends Model
{
    /** @use HasFactory<CasoPruebaFactory> */
    use HasFactory;

    // Fase 1 = pruebas del sistema con tu NIT. Fase 3 = piloto por cliente.
    public const FASE_SISTEMA = 1;

    public const FASE_PILOTO = 3;

    protected $table = 'casos_prueba';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload_ejemplo' => 'array',
            'obligatorio' => 'boolean',
        ];
    }

    public function ejecuciones(): HasMany
    {
        return $this->hasMany(EjecucionPrueba::class, 'caso_id');
    }
}
