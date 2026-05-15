<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiatCuis extends Model
{
    protected $table = 'siat_cuis';

    protected $fillable = [
        'codigo_sucursal',
        'codigo_punto_venta',
        'codigo',
        'fechaVigencia',
        'transaccion',
        'status',
    ];

    protected $casts = [
        'transaccion' => 'boolean',
    ];

    /**
     * Retorna true si el CUIS no existe, ya venció, o está inactivo.
     * Si fechaVigencia es null, se trata como vigente indefinidamente.
     */
    public function isExpired(): bool
    {
        if (!$this->fechaVigencia) {
            return false;
        }

        return now()->gte(\Carbon\Carbon::parse($this->fechaVigencia));
    }

    /**
     * Scope: CUIS activo y vigente para una sucursal/PV específicos.
     */
    public function scopeActive($query, int $codigoSucursal, int $codigoPuntoVenta)
    {
        return $query
            ->where('status', 1)
            ->where('codigo_sucursal', $codigoSucursal)
            ->where('codigo_punto_venta', $codigoPuntoVenta)
            ->latest();
    }
}
