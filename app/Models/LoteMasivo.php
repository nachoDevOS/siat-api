<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lote de emision masiva de un punto de venta. Abierto, las facturas del
 * punto de venta nacen MASIVA; al cerrarlo se arman y envian los paquetes.
 */
class LoteMasivo extends Model
{
    public const ESTADO_ABIERTO = 'ABIERTO';

    public const ESTADO_CERRADO = 'CERRADO';

    /** Tope de facturas por paquete de recepcionMasivaFactura. */
    public const FACTURAS_POR_PAQUETE = 500;

    protected $table = 'lotes_masivos';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'abierto_en' => 'datetime',
            'cerrado_en' => 'datetime',
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

    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class);
    }

    public function paquetes(): HasMany
    {
        return $this->hasMany(Paquete::class);
    }
}
