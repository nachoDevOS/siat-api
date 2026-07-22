<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Actividad economica registrada de un NIT. Depende de la empresa porque el
 * contenido cambia segun el contribuyente que consulta al SIN.
 */
class ActividadEconomica extends Model
{
    protected $table = 'actividades_economicas';

    protected $guarded = ['id'];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
