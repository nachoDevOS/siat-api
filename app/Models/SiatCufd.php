<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiatCufd extends Model
{
    protected $table = 'siat_cufd';

    protected $fillable = [
        'codigo_sucursal',
        'codigo_punto_venta',
        'codigo',
        'codigoControl',
        'direccion',
        'fechaVigencia',
        'transaccion',
        'status',
    ];

    protected $casts = [
        'transaccion' => 'boolean',
    ];

    public function isExpired(): bool
    {
        if (!$this->fechaVigencia) {
            return false;
        }

        return now()->gte(\Carbon\Carbon::parse($this->fechaVigencia));
    }

    public function scopeActive($query, int $codigoSucursal, int $codigoPuntoVenta)
    {
        return $query
            ->where('status', 1)
            ->where('codigo_sucursal', $codigoSucursal)
            ->where('codigo_punto_venta', $codigoPuntoVenta)
            ->latest();
    }
}
