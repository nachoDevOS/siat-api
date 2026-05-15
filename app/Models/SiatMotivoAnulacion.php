<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiatMotivoAnulacion extends Model
{
    protected $table = 'siat_motivos_anulacion';
    protected $fillable = ['codigoClasificador', 'descripcion'];
}
