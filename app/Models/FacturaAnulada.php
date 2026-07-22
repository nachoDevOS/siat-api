<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registro de la anulacion de una factura. Se guarda aparte para no perder el
 * dato de la factura original, que queda en estado ANULADA.
 */
class FacturaAnulada extends Model
{
    protected $table = 'facturas_anuladas';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'anulada_en' => 'datetime',
        ];
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }
}
