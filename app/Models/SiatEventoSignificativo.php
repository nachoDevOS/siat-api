<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiatEventoSignificativo extends Model
{
    protected $table = 'siat_eventos_significativos';
    protected $fillable = ['codigoClasificador', 'descripcion'];
}
