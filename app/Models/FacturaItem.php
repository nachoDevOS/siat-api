<?php

namespace App\Models;

use Database\Factories\FacturaItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un detalle (linea) de una factura. Guarda el codigo homologado del SIN y el
 * codigo interno del cliente para poder rastrear el producto en ambos sistemas.
 */
class FacturaItem extends Model
{
    /** @use HasFactory<FacturaItemFactory> */
    use HasFactory;

    protected $table = 'factura_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cantidad' => 'decimal:4',
            'precio_unitario' => 'decimal:2',
            'descuento' => 'decimal:2',
            'subtotal' => 'decimal:2',
        ];
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }
}
