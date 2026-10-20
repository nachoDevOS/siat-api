<?php

namespace App\Models;

use Database\Factories\EmpresaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;

/**
 * Un cliente contribuyente del proveedor. Es la raiz del aislamiento
 * multi-cliente: casi todo cuelga de empresa_id.
 */
class Empresa extends Model
{
    /** @use HasFactory<EmpresaFactory> */
    use HasApiTokens, HasFactory;

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
     * Igual que el default de la columna, para que una empresa recien creada
     * (sin volver a leerla) ya arme el XML V3 con su codigoInterno.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'codigo_interno' => 'A',
    ];

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
     * Emite un token de API de Sanctum para un sistema de ventas de esta
     * empresa. El token en claro solo existe en el valor devuelto: Sanctum
     * guarda su hash, asi que hay que mostrarlo una vez y no se recupera.
     *
     * @param  string  $perfil  clave de config('siat.api.perfiles').
     * @param  list<string>  $ipsPermitidas  vacio = desde cualquier IP.
     */
    public function emitirTokenApi(string $nombre, string $perfil, ?Carbon $venceEl = null, array $ipsPermitidas = []): NewAccessToken
    {
        $permisos = config("siat.api.perfiles.{$perfil}.permisos");

        if (! is_array($permisos)) {
            throw new \InvalidArgumentException("Perfil de token desconocido: '{$perfil}'.");
        }

        $nuevo = $this->createToken($nombre, $permisos, $venceEl);

        if ($ipsPermitidas !== []) {
            $nuevo->accessToken->forceFill(['ips_permitidas' => array_values($ipsPermitidas)])->save();
        }

        return $nuevo;
    }

    /**
     * Secreto con el que se firman (HMAC) los webhooks de ESTA empresa.
     *
     * Antes era uno solo para todos los clientes: cualquiera de ellos podia
     * firmar un aviso falso dirigido a otro. Se deriva del secreto global y del
     * id, asi que es distinto por empresa sin guardar nada nuevo, y rotar el
     * global rota todos. Null si el proveedor no configuro el global.
     */
    public function secretoWebhook(): ?string
    {
        $base = config('siat.webhooks.secreto');

        if (blank($base)) {
            return null;
        }

        return hash_hmac('sha256', "webhook:{$this->id}", $base);
    }

    /**
     * Solo puede facturar por la API una empresa que ya paso todas las fases.
     */
    public function estaEnProduccion(): bool
    {
        return $this->estado === self::ESTADO_PRODUCCION;
    }

    /**
     * Si la empresa trabaja contra el SIAT PILOTO: lo que emite no tiene
     * validez fiscal. Es el sandbox del sistema de ventas del cliente.
     */
    public function esSandbox(): bool
    {
        return $this->codigo_ambiente === config('siat.codigos.ambiente.piloto');
    }

    /**
     * Si el sistema de ventas puede emitir por la API.
     *
     * En produccion, solo con la empresa habilitada. En el piloto tambien
     * mientras corre o ya aprobo las pruebas ante el SIN: asi el
     * desarrollador del cliente integra emitiendo de verdad contra el SIAT
     * piloto, sin esperar a produccion. En registro todavia no hay punto de
     * venta ni certificado; OBSERVADO es un cliente que se dejo de atender.
     */
    public function puedeEmitirPorApi(): bool
    {
        if ($this->estaEnProduccion()) {
            return true;
        }

        return $this->esSandbox()
            && in_array($this->estado, [self::ESTADO_EN_PRUEBAS, self::ESTADO_PILOTO_APROBADO], true);
    }

    /**
     * Token en claro con el ambiente a la vista: siat_test_ (piloto) o
     * siat_live_ (produccion), como las claves sk_test/sk_live de otras APIs.
     * Quien lo pega en su sistema sabe al instante si factura de verdad.
     *
     * Pisa el de HasApiTokens, que usa un prefijo fijo de config.
     */
    public function generateTokenString(): string
    {
        $prefijo = $this->esSandbox() ? 'siat_test_' : 'siat_live_';
        $entropia = Str::random(40);

        return $prefijo.$entropia.hash('crc32b', $entropia);
    }

    /**
     * Empresa de produccion de la que salio este sandbox (null si no es una
     * copia).
     */
    public function sandboxDe(): BelongsTo
    {
        return $this->belongsTo(self::class, 'sandbox_de_id');
    }

    /**
     * Sandbox de esta empresa de produccion, si se creo.
     */
    public function sandbox(): HasOne
    {
        return $this->hasOne(self::class, 'sandbox_de_id');
    }

    /**
     * Deja a la empresa con UN token nuevo y revoca los que tuviera.
     *
     * Regla del proveedor: un token por empresa. El sistema de ventas del
     * cliente lo usa para todas sus sucursales y puntos de venta (cada venta
     * dice en cual se emite). Reemplazar en una transaccion garantiza que
     * nunca queden dos vigentes ni ninguno a medias.
     *
     * @param  list<string>  $ipsPermitidas
     */
    public function reemplazarTokenApi(string $perfil, ?Carbon $venceEl = null, array $ipsPermitidas = []): NewAccessToken
    {
        return DB::transaction(function () use ($perfil, $venceEl, $ipsPermitidas) {
            $this->tokens()->delete();

            return $this->emitirTokenApi("Token de {$this->nombre_comercial}", $perfil, $venceEl, $ipsPermitidas);
        });
    }

    /**
     * Fecha en que vence el token delegado del SIN, o null si no se puede
     * saber (sin token, o un token que no es un JWT con 'exp').
     *
     * El token es un JWT: su vencimiento viaja en el propio token, asi que se
     * lee sin consultar al SIN. No se verifica la firma: solo interesa la
     * fecha para avisar a tiempo, y la validez la decide el SIN en cada
     * llamada. Cuando vence, TODA operacion del cliente falla por
     * autenticacion y el error del SIN no lo dice claro.
     */
    public function vencimientoTokenDelegado(): ?Carbon
    {
        $partes = explode('.', (string) $this->token_delegado);

        if (count($partes) !== 3) {
            return null;
        }

        $carga = json_decode((string) base64_decode(strtr($partes[1], '-_', '+/'), true), true);
        $exp = is_array($carga) ? ($carga['exp'] ?? null) : null;

        // En la zona horaria de la app: createFromTimestamp devuelve UTC, y
        // entre las 20:00 y las 24:00 de Bolivia la ficha mostraba un dia mas.
        return is_numeric($exp) ? Carbon::createFromTimestamp((int) $exp, config('app.timezone')) : null;
    }

    /**
     * Un punto de venta de la empresa con CUIS vigente, o null si no hay.
     *
     * Las operaciones de catalogo piden CUIS, sucursal y punto de venta, y el
     * SIN valida que el CUIS sea de ESE punto de venta: por eso se devuelve el
     * punto de venta entero y no un CUIS suelto. Cualquiera sirve, porque los
     * catalogos dependen del NIT y no del punto de venta.
     */
    public function puntoVentaConCuisVigente(): ?PuntoVenta
    {
        return PuntoVenta::with('sucursal.empresa')
            ->whereHas('sucursal', fn ($q) => $q->where('empresa_id', $this->id))
            ->orderBy('id')
            ->get()
            ->first(fn (PuntoVenta $pv) => $pv->cuisVigente() !== null);
    }

    public function certificadoActivo(): HasOne
    {
        return $this->hasOne(Certificado::class)->where('activo', true);
    }

    public function certificados(): HasMany
    {
        return $this->hasMany(Certificado::class);
    }

    /**
     * CAFC con el que se factura a mano en los eventos 5, 6 y 7.
     */
    public function cafcActivo(): HasOne
    {
        return $this->hasOne(Cafc::class)->where('activo', true);
    }

    public function cafcs(): HasMany
    {
        return $this->hasMany(Cafc::class);
    }

    public function sucursales(): HasMany
    {
        return $this->hasMany(Sucursal::class);
    }

    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class);
    }
}
