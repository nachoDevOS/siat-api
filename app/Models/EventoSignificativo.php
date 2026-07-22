<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Evento significativo: se registra cuando el SIAT se cae para habilitar la
 * emision en contingencia. Se cierra cuando el SIAT vuelve.
 */
class EventoSignificativo extends Model
{
    protected $table = 'eventos_significativos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'datetime',
            'fecha_fin' => 'datetime',
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

    public function paquetes(): HasMany
    {
        return $this->hasMany(Paquete::class, 'evento_id');
    }
}
