<?php

namespace App\Models;

use Database\Factories\CufdFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CUFD: codigo unico de facturacion diaria. Dura 24 horas. Su "codigo_control"
 * entra al calculo del CUF, por eso se guarda por separado.
 */
class Cufd extends Model
{
    /** @use HasFactory<CufdFactory> */
    use HasFactory;

    protected $table = 'cufd';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha_vigencia' => 'datetime',
        ];
    }

    /**
     * Estado para el panel. Un CUFD dentro de sus 24 horas pero atado a un
     * CUIS que ya no sirve tampoco sirve: el SIN lo rechaza con 913.
     *
     * @return 'vigente'|'cuis_cerrado'|'vencido'|'reemplazado'
     */
    public function estadoFrente(?self $enUso): string
    {
        return match (true) {
            $enUso !== null && $this->id === $enUso->id => 'vigente',
            $this->fecha_vigencia->isPast() => 'vencido',
            $this->cuis?->fecha_vigencia?->isPast() ?? false => 'cuis_cerrado',
            default => 'reemplazado',
        };
    }

    public function puntoVenta(): BelongsTo
    {
        return $this->belongsTo(PuntoVenta::class);
    }

    /**
     * CUIS con el que se pidio este CUFD. Null en los guardados antes de que se
     * empezara a registrar el vinculo.
     */
    public function cuis(): BelongsTo
    {
        return $this->belongsTo(Cuis::class);
    }
}
