<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Leyenda de factura. Depende de la actividad economica: cada actividad tiene
 * sus leyendas obligatorias que el SIN devuelve por NIT.
 */
class LeyendaFactura extends Model
{
    protected $table = 'leyendas_factura';

    protected $guarded = ['id'];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
