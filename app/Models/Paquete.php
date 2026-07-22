<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Paquete de facturas de contingencia que se envian juntas al SIAT una vez que
 * el servicio vuelve y se cierra el evento significativo.
 */
class Paquete extends Model
{
    protected $table = 'paquetes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enviado_en' => 'datetime',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function puntoVenta(): BelongsTo
    {
        return $this->belongsTo(PuntoVenta::class);
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(EventoSignificativo::class, 'evento_id');
    }
}
