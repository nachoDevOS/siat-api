<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvoicePackage extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'codigoEventoSignificativo',
        'descripcion',
        'fechaHoraInicioEvento',
        'fechaHoraFinEvento',
        'codigoRecepcionEventoSignificativo',
        'codigo_sucursal',
        'codigo_punto_venta',
        'codigoEstado',
        'codigoDescripcion',
        'codigoRecepcion',
        'transaccion',
    ];

    protected $casts = [
        'transaccion' => 'boolean',
    ];

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }
}
