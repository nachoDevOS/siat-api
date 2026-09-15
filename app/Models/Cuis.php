<?php

namespace App\Models;

use Database\Factories\CuisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CUIS: codigo de identificacion del punto de venta ante el SIN. Dura ~1 anio.
 * Es historial: cada solicitud crea una fila nueva, nunca se sobreescribe.
 */
class Cuis extends Model
{
    /** @use HasFactory<CuisFactory> */
    use HasFactory;

    // Laravel no pluraliza "Cuis" al nombre real de la tabla, se fija a mano.
    protected $table = 'cuis';

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

    /**
     * CUFD emitidos con este CUIS. El SIN exige el CUIS vigente para dar un
     * CUFD, asi que cada uno cuelga de exactamente uno.
     */
    public function cufds(): HasMany
    {
        return $this->hasMany(Cufd::class);
    }

    public function estaVigente(): bool
    {
        return $this->fecha_vigencia->isFuture();
    }
}
