<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * Facturas que viajan dentro de este paquete. El conjunto se congela al
     * armarlo para que una factura posterior no se de por enviada.
     */
    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class);
    }
}
