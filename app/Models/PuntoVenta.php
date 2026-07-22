<?php

namespace App\Models;

use Database\Factories\PuntoVentaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Punto de venta de una sucursal. A diferencia de la sucursal, este SI lo crea
 * el sistema en el SIAT. Lleva el correlativo local de facturas.
 */
class PuntoVenta extends Model
{
    /** @use HasFactory<PuntoVentaFactory> */
    use HasFactory;

    protected $table = 'puntos_venta';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'codigo_punto_venta' => 'integer',
            'tipo_punto_venta' => 'integer',
            'siguiente_factura' => 'integer',
            'activo' => 'boolean',
        ];
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function cuis(): HasMany
    {
        return $this->hasMany(Cuis::class);
    }

    public function cufds(): HasMany
    {
        return $this->hasMany(Cufd::class);
    }

    public function cafcs(): HasMany
    {
        return $this->hasMany(Cafc::class);
    }

    /**
     * CUFD vigente: el mas reciente cuya fecha_vigencia todavia no paso.
     * Devuelve null si no hay ninguno y hay que solicitar uno nuevo.
     */
    public function cufdVigente(): ?Cufd
    {
        return $this->cufds()
            ->where('fecha_vigencia', '>', now())
            ->latest('fecha_vigencia')
            ->first();
    }

    /**
     * CUIS vigente por la misma logica que el CUFD.
     */
    public function cuisVigente(): ?Cuis
    {
        return $this->cuis()
            ->where('fecha_vigencia', '>', now())
            ->latest('fecha_vigencia')
            ->first();
    }
}
