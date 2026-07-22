<?php

namespace App\Models;

use Database\Factories\CafcFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CAFC: reserva de rango de facturas para emitir en contingencia (sin internet
 * o SIAT caido). "facturas_usadas" lleva el consumo del rango.
 */
class Cafc extends Model
{
    /** @use HasFactory<CafcFactory> */
    use HasFactory;

    protected $table = 'cafc';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cantidad_facturas' => 'integer',
            'facturas_usadas' => 'integer',
            'fecha_vigencia' => 'datetime',
        ];
    }

    public function puntoVenta(): BelongsTo
    {
        return $this->belongsTo(PuntoVenta::class);
    }

    /**
     * Un CAFC sirve como reserva solo si esta vigente y aun le quedan folios.
     */
    public function tieneDisponibilidad(): bool
    {
        return $this->fecha_vigencia->isFuture()
            && $this->facturas_usadas < $this->cantidad_facturas;
    }
}
