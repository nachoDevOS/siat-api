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
            'cerrado_en' => 'datetime',
        ];
    }

    /**
     * Estado para el panel, comparado contra el que el punto de venta usa hoy.
     *
     * Vigente es uno solo por punto de venta, sin importar la version: el SIN
     * comparte el CUIS entre V2 y V3 (verificado el 19/10/2026). Los demas se
     * distinguen por por que dejaron de servir.
     *
     * @return 'vigente'|'cerrado'|'vencido'|'reemplazado'
     */
    public function estadoFrente(?self $enUso): string
    {
        return match (true) {
            $enUso !== null && $this->id === $enUso->id => 'vigente',
            $this->cerrado_en !== null => 'cerrado',
            $this->fecha_vigencia->isPast() => 'vencido',
            default => 'reemplazado',
        };
    }

    /**
     * Lo marca cerrado: el SIN ya no lo acepta, asi que deja de ser vigente
     * tambien en la base (si no, se seguiria usando y todo volveria con 913).
     */
    public function marcarCerrado(): void
    {
        $this->update(['fecha_vigencia' => now(), 'cerrado_en' => now()]);
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
}
