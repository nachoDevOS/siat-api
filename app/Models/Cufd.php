<?php

namespace App\Models;

use Database\Factories\CufdFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CUFD: codigo unico de facturacion diaria. Dura 24 horas. Su "codigo_control"
 * entra al calculo del CUF, por eso se guarda por separado.
 */
class Cufd extends Model
{
    /** @use HasFactory<CufdFactory> */
    use HasFactory;

    protected $table = 'cufd';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha_vigencia' => 'datetime',
        ];
    }

    public function puntoVenta(): BelongsTo
    {
        return $this->belongsTo(PuntoVenta::class);
    }

    public function estaVigente(): bool
    {
        return $this->fecha_vigencia->isFuture();
    }
}
