<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Producto o servicio homologado por el SIN segun las actividades del NIT.
 * El "codigo_producto" es el que va en la factura (codigo_producto_sin).
 */
class ProductoServicio extends Model
{
    protected $table = 'productos_servicios';

    protected $guarded = ['id'];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
