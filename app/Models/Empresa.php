<?php

namespace App\Models;

use Database\Factories\EmpresaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Un cliente contribuyente del proveedor. Es la raiz del aislamiento
 * multi-cliente: casi todo cuelga de empresa_id.
 */
class Empresa extends Model
{
    /** @use HasFactory<EmpresaFactory> */
    use HasFactory;

    // Estados del ciclo de vida ante el SIN (seccion 12.3 del documento).
    // Se declaran como constantes para no repetir cadenas sueltas por el codigo.
    public const ESTADO_EN_REGISTRO = 'EN_REGISTRO';

    public const ESTADO_EN_PRUEBAS = 'EN_PRUEBAS';

    public const ESTADO_PILOTO_APROBADO = 'PILOTO_APROBADO';

    public const ESTADO_PRODUCCION = 'PRODUCCION';

    public const ESTADO_OBSERVADO = 'OBSERVADO';

    protected $table = 'empresas';

    protected $guarded = ['id'];

    /**
     * El token delegado se cifra en reposo con la clave de la app.
     * El cast 'encrypted' lo descifra transparente al leerlo.
     */
    protected function casts(): array
    {
        return [
            'token_delegado' => 'encrypted',
            'codigo_ambiente' => 'integer',
            'codigo_modalidad' => 'integer',
        ];
    }

    /**
     * Solo puede facturar por la API una empresa que ya paso todas las fases.
     */
    public function estaEnProduccion(): bool
    {
        return $this->estado === self::ESTADO_PRODUCCION;
    }

    public function certificadoActivo(): HasOne
    {
        return $this->hasOne(Certificado::class)->where('activo', true);
    }

    public function certificados(): HasMany
    {
        return $this->hasMany(Certificado::class);
    }

    public function sucursales(): HasMany
    {
        return $this->hasMany(Sucursal::class);
    }
}
