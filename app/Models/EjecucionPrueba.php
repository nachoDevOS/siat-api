<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ejecucion de un caso de prueba. Guarda la respuesta cruda del SIN y el tiempo
 * de ejecucion como evidencia del piloto.
 */
class EjecucionPrueba extends Model
{
    protected $table = 'ejecuciones_prueba';

    protected $guarded = ['id'];

    public const ESTADO_PENDIENTE = 'PENDIENTE';

    public const ESTADO_EXITOSO = 'EXITOSO';

    public const ESTADO_FALLIDO = 'FALLIDO';

    protected function casts(): array
    {
        return [
            'respuesta' => 'array',
            'ejecutado_en' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function caso(): BelongsTo
    {
        return $this->belongsTo(CasoPrueba::class, 'caso_id');
    }
}
