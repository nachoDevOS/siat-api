<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'external_id',
        'invoice_package_id',
        'nroFactura',
        'cuf',
        'codigo_sucursal',
        'codigo_punto_venta',
        'fechaEmision',
        'codigoMetodoPago',
        'montoTotal',
        'montoTotalSujetoIva',
        'descuentoAdicional',
        'xml',
        'codigoEstado',
        'codigoDescripcion',
        'codigoRecepcion',
        'transaccion',
        'reversed',
    ];

    protected $casts = [
        'transaccion' => 'boolean',
        'reversed'    => 'boolean',
    ];

    public function invoicePackage()
    {
        return $this->belongsTo(InvoicePackage::class);
    }

    /** Factura pendiente de envío al SIN. */
    public function isPending(): bool
    {
        return $this->codigoEstado === '902';
    }

    /** Factura confirmada por el SIN. */
    public function isValidated(): bool
    {
        return in_array($this->codigoEstado, ['908', '690'], true)
            || strtoupper((string) $this->codigoDescripcion) === 'VALIDADA';
    }
}
