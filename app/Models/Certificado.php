<?php

namespace App\Models;

use Database\Factories\CertificadoFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Certificado digital .p12 de una empresa. El contenido y la passphrase se
 * guardan cifrados y jamas se exponen por la API: son la llave de la firma.
 */
class Certificado extends Model
{
    /** @use HasFactory<CertificadoFactory> */
    use HasFactory;

    /**
     * Emisor con el que se guardan los generados por el sistema para el piloto
     * (GeneradorCertificadoPrueba). Es lo que permite reconocerlos despues.
     */
    public const EMISOR_AUTOFIRMADO = 'AUTOFIRMADO - solo pruebas';

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

    /**
     * Si es un autofirmado de prueba: no sirve para facturar en produccion.
     */
    public function esDePrueba(): bool
    {
        return $this->emitido_por === self::EMISOR_AUTOFIRMADO;
    }

    /**
     * Fecha de vencimiento que declara un certificado X509 (PEM), en hora de
     * Bolivia. En UTC, un certificado que vence de madrugada (hora UTC)
     * quedaba guardado con un dia de mas.
     */
    public static function vencimientoDeX509(string $pem): ?Carbon
    {
        $datos = openssl_x509_parse($pem);

        if ($datos === false || ! isset($datos['validTo_time_t'])) {
            return null;
        }

        return Carbon::createFromTimestamp($datos['validTo_time_t'], config('app.timezone'));
    }

    /**
     * Vencimiento leido de su propio .p12, o null si no se puede abrir.
     */
    public function vencimientoDelP12(): ?Carbon
    {
        $almacen = [];
        $binario = base64_decode((string) $this->contenido_p12, true);

        if ($binario === false || ! openssl_pkcs12_read($binario, $almacen, (string) $this->passphrase)) {
            return null;
        }

        return self::vencimientoDeX509((string) ($almacen['cert'] ?? ''));
    }

    /**
     * Momento desde el que vale el certificado, leido de su propio .p12, en
     * hora de Bolivia; null si no se puede abrir.
     *
     * El SIN rechaza con 965 ("NO CUENTA CON FIRMA VIGENTE") una factura
     * fechada antes de este momento, aunque se firme despues: pasa con los
     * paquetes fuera de linea, cuyas facturas llevan la fecha del evento.
     */
    public function inicioVigenciaDelP12(): ?Carbon
    {
        $almacen = [];
        $binario = base64_decode((string) $this->contenido_p12, true);

        if ($binario === false || ! openssl_pkcs12_read($binario, $almacen, (string) $this->passphrase)) {
            return null;
        }

        $datos = openssl_x509_parse((string) ($almacen['cert'] ?? ''));

        if ($datos === false || ! isset($datos['validFrom_time_t'])) {
            return null;
        }

        return Carbon::createFromTimestamp($datos['validFrom_time_t'], config('app.timezone'));
    }

    /**
     * Si ya vencio. Vale hasta el final del dia de vencimiento. Sin fecha
     * cargada no se puede afirmar que este vencido.
     */
    public function estaVencido(): bool
    {
        return $this->vence_el !== null && $this->vence_el->copy()->endOfDay()->isPast();
    }

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class);
    }
}
