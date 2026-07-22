<?php

namespace App\Models;

use Database\Factories\SucursalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sucursal de una empresa. El contribuyente la registra en su Oficina Virtual;
 * el sistema solo la copia para poder referenciarla al facturar.
 */
class Sucursal extends Model
{
    /** @use HasFactory<SucursalFactory> */
    use HasFactory;

    // El SIN identifica la sucursal por un entero, no por el id interno.
    // 0 es siempre la casa matriz.
    public const CODIGO_CASA_MATRIZ = 0;

    protected $table = 'sucursales';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'codigo_sucursal' => 'integer',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }

    public function puntosVenta(): HasMany
    {
        return $this->hasMany(PuntoVenta::class);
    }
}
