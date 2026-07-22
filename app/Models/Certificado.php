<?php

namespace App\Models;

use Database\Factories\CertificadoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Certificado digital .p12 de una empresa. El contenido y la passphrase se
 * guardan cifrados y jamas se exponen por la API: son la llave de la firma.
 */
class Certificado extends Model
{
    /** @use HasFactory<CertificadoFactory> */
    use HasFactory;

    protected $table = 'certificados';

    protected $guarded = ['id'];

    /**
     * Ocultamos los campos sensibles por si el modelo se serializa por error.
     */
    protected $hidden = ['contenido_p12', 'passphrase'];

    protected function casts(): array
    {
        return [
            // El .p12 se guarda en base64 y luego cifrado; el passphrase igual.
            'contenido_p12' => 'encrypted',
            'passphrase' => 'encrypted',
            'vence_el' => 'date',
            'activo' => 'boolean',
        ];
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
