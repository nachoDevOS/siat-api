<?php

namespace App\Services\Panel;

use App\Models\LogSiat;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Lee una llamada guardada en logs_siat para mostrarla en el panel.
 *
 * La columna 'exitoso' solo dice si HUBO comunicacion: el SIN rechaza con HTTP
 * 200 y transaccion=false, asi que un rechazo queda con exitoso=1. Aca se
 * distinguen los tres casos que importan al operador:
 *
 *   ACEPTADA       el SIN respondio y no dijo transaccion=false;
 *   RECHAZADA      el SIN respondio transaccion=false (el motivo viene adentro);
 *   SIN_RESPUESTA  no hubo respuesta: timeout, SIAT caido, WSDL que no carga.
 *
 * Tambien abre el <archivo> que viaja en gzip y base64 (la factura firmada o
 * el TAR del paquete) y recalcula su hash: es lo que se hacia a mano para
 * comparar una factura aceptada con una rechazada.
 */
class LecturaLogSiat
{
    public const ACEPTADA = 'ACEPTADA';

    public const RECHAZADA = 'RECHAZADA';

    public const SIN_RESPUESTA = 'SIN_RESPUESTA';

    /**
     * Etiqueta y color de cada resultado, en la paleta unica del panel.
     *
     * @var array<string, array{texto: string, color: string}>
     */
    public const VISUAL = [
        self::ACEPTADA => ['texto' => 'Aceptada', 'color' => 'verde'],
        self::RECHAZADA => ['texto' => 'Rechazada por el SIN', 'color' => 'ambar'],
        self::SIN_RESPUESTA => ['texto' => 'Sin respuesta', 'color' => 'rojo'],
    ];

    /** Lo que el SIN escribe en la respuesta cuando rechaza. */
    private const MARCA_RECHAZO = '%<transaccion>false</transaccion>%';

    /** Tope al descomprimir: una factura pesa KB; esto corta un archivo raro. */
    private const MAXIMO_DESCOMPRIMIDO = 20 * 1024 * 1024;

    private ?DOMXPath $peticion = null;

    private ?DOMXPath $respuestaXml = null;

    public function __construct(public readonly LogSiat $log) {}

    /**
     * Filtra una consulta de logs_siat por resultado, en SQL, para que el
     * filtro y la paginacion coincidan.
     */
    public static function filtrarPorResultado(Builder $consulta, string $resultado): void
    {
        match ($resultado) {
            self::SIN_RESPUESTA => $consulta->where('exitoso', false),
            self::RECHAZADA => $consulta->where('exitoso', true)->where('xml_recibido', 'like', self::MARCA_RECHAZO),
            self::ACEPTADA => $consulta->where('exitoso', true)->where(fn ($q) => $q
                ->whereNull('xml_recibido')
                ->orWhere('xml_recibido', 'not like', self::MARCA_RECHAZO)),
            default => null,
        };
    }

    /**
     * Expresion SQL que vale 1 cuando el SIN rechazo: sirve para contar sin
     * traer los XML completos a memoria.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function sqlRechazada(): array
    {
        return ['CASE WHEN exitoso = 1 AND xml_recibido LIKE ? THEN 1 ELSE 0 END', [self::MARCA_RECHAZO]];
    }

    public function resultado(): string
    {
        if (! $this->log->exitoso) {
            return self::SIN_RESPUESTA;
        }

        return str_contains((string) $this->log->xml_recibido, '<transaccion>false</transaccion>')
            ? self::RECHAZADA
            : self::ACEPTADA;
    }

    /**
     * Lo que dijo el SIN, leido del sobre de respuesta.
     *
     * @return array{transaccion: ?bool, codigoEstado: ?string, descripcion: ?string, codigoRecepcion: ?string, mensajes: list<array{codigo: string, descripcion: string}>}
     */
    public function respuesta(): array
    {
        $xpath = $this->respuestaXpath();

        $texto = fn (string $nombre): ?string => $xpath === null ? null
            : ($xpath->query("//*[local-name()='{$nombre}']")->item(0)?->textContent);

        $transaccion = $texto('transaccion');
        $mensajes = [];

        foreach ($xpath?->query("//*[local-name()='mensajesList']") ?? [] as $mensaje) {
            $mensajes[] = [
                'codigo' => (string) $xpath->query("*[local-name()='codigo']", $mensaje)->item(0)?->textContent,
                'descripcion' => (string) $xpath->query("*[local-name()='descripcion']", $mensaje)->item(0)?->textContent,
            ];
        }

        return [
            'transaccion' => $transaccion === null ? null : $transaccion === 'true',
            'codigoEstado' => $texto('codigoEstado'),
            'descripcion' => $texto('codigoDescripcion'),
            'codigoRecepcion' => $texto('codigoRecepcion'),
            'mensajes' => $mensajes,
        ];
    }

    /**
     * Resumen de una linea para el listado: el motivo del rechazo, el estado o
     * el error de conexion.
     */
    public function resumen(): string
    {
        if ($this->resultado() === self::SIN_RESPUESTA) {
            return (string) $this->log->mensaje_error;
        }

        $respuesta = $this->respuesta();

        if ($respuesta['mensajes'] !== []) {
            return collect($respuesta['mensajes'])->map(fn (array $m) => "[{$m['codigo']}] {$m['descripcion']}")->implode(' | ');
        }

        return trim(($respuesta['codigoEstado'] !== null ? "[{$respuesta['codigoEstado']}] " : '').($respuesta['descripcion'] ?? ''));
    }

    /**
     * Campos propios de la respuesta (codigo del CUFD, vigencia, ...). Las
     * listas largas de los catalogos se resumen en su cantidad.
     *
     * @return array<string, string>
     */
    public function camposRespuesta(): array
    {
        $raiz = $this->respuestaXpath()?->query("//*[local-name()='Body']/*/*")->item(0);

        return $raiz instanceof DOMElement ? $this->aplanar($raiz, ['mensajesList', 'transaccion', 'codigoEstado', 'codigoDescripcion', 'codigoRecepcion']) : [];
    }

    /**
     * Campos que se mandaron en la solicitud. El <archivo> se reemplaza por su
     * tamano: su contenido se muestra decodificado aparte.
     *
     * @return array<string, string>
     */
    public function parametros(): array
    {
        $solicitud = $this->peticionXpath()?->query("//*[local-name()='Body']/*/*")->item(0);

        if (! $solicitud instanceof DOMElement) {
            return [];
        }

        $campos = $this->aplanar($solicitud);

        if (isset($campos['archivo'])) {
            $bytes = strlen((string) base64_decode($campos['archivo'], true));
            $campos['archivo'] = "(adjunto comprimido, {$bytes} bytes: ver documento)";
        }

        return $campos;
    }

    /**
     * Documentos que viajaron dentro del <archivo>: la factura firmada de una
     * recepcion o cada factura del TAR de un paquete.
     *
     * @return list<array{nombre: string, xml: string}>
     */
    public function documentos(): array
    {
        $binario = $this->archivoBinario();

        if ($binario === null) {
            return [];
        }

        $contenido = str_starts_with($binario, "\x1f\x8b")
            ? @gzdecode($binario, self::MAXIMO_DESCOMPRIMIDO)
            : $binario;

        if (! is_string($contenido)) {
            return [];
        }

        // Paquete de contingencia: un TAR (formato ustar) con un XML por factura.
        if (substr($contenido, 257, 5) === 'ustar') {
            return $this->leerTar($contenido);
        }

        return [['nombre' => 'documento', 'xml' => $contenido]];
    }

    /**
     * Hash declarado contra el calculado sobre el gzip que viajo. Si no
     * coinciden, el SIN rechaza el envio por integridad.
     *
     * @return array{declarado: string, calculado: string, coincide: bool}|null
     */
    public function hash(): ?array
    {
        $declarado = $this->peticionXpath()?->query("//*[local-name()='hashArchivo']")->item(0)?->textContent;
        $binario = $this->archivoBinario();

        if (blank($declarado) || $binario === null) {
            return null;
        }

        $calculado = hash('sha256', $binario);

        return ['declarado' => $declarado, 'calculado' => $calculado, 'coincide' => hash_equals($calculado, strtolower($declarado))];
    }

    /**
     * Valor de un campo del primer documento (por ejemplo 'cuf'), para enlazar
     * la llamada con la factura del panel.
     */
    public function campoDelDocumento(string $nombre): ?string
    {
        $xml = $this->documentos()[0]['xml'] ?? null;

        if ($xml === null || ! preg_match("#<{$nombre}>([^<]*)</{$nombre}>#", $xml, $coincidencia)) {
            return $this->parametros()[$nombre] ?? null;
        }

        return $coincidencia[1];
    }

    /**
     * XML indentado para leerlo. Si no es XML valido se devuelve tal cual.
     *
     * @param  bool  $acortarFirma  reemplaza los base64 largos de la firma
     *                              (valor y certificado) por su largo.
     */
    public static function legible(?string $xml, bool $acortarFirma = false): string
    {
        if (blank($xml)) {
            return '';
        }

        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        if (! @$doc->loadXML(trim($xml), LIBXML_NONET)) {
            return $xml;
        }

        if ($acortarFirma) {
            foreach (['SignatureValue', 'X509Certificate', 'archivo'] as $nombre) {
                foreach ($doc->getElementsByTagNameNS('*', $nombre) as $nodo) {
                    $largo = strlen($nodo->textContent);
                    $nodo->textContent = $largo > 80 ? "… ({$largo} caracteres)" : $nodo->textContent;
                }
            }
        }

        return (string) $doc->saveXML($doc->documentElement);
    }

    /**
     * XML con colores para leerlo en pantalla: etiquetas, atributos y valores.
     *
     * Se escapa TODO primero y recien despues se agregan los <span>: el XML
     * viene del SIN y de los clientes, nunca se imprime sin escapar.
     */
    public static function resaltar(string $xml): HtmlString
    {
        $escapado = e($xml);

        $resaltado = preg_replace(
            [
                '/(&lt;\/?)([\w:.-]+)/',
                '/([\w:.-]+)=(&quot;.*?&quot;)/',
                '/(\/?&gt;)/',
            ],
            [
                '$1<span class="x-tag">$2</span>',
                '<span class="x-attr">$1</span>=<span class="x-val">$2</span>',
                '<span class="x-tag">$1</span>',
            ],
            $escapado,
        );

        return new HtmlString((string) $resaltado);
    }

    /**
     * Hijos de un elemento como "campo => valor", un nivel de anidamiento. Un
     * elemento repetido (lista de catalogo) se resume en su cantidad.
     *
     * @param  list<string>  $omitir
     * @return array<string, string>
     */
    private function aplanar(DOMElement $padre, array $omitir = []): array
    {
        $campos = [];
        $repetidos = [];

        foreach ($padre->childNodes as $hijo) {
            if ($hijo instanceof DOMElement) {
                $repetidos[$hijo->localName] = ($repetidos[$hijo->localName] ?? 0) + 1;
            }
        }

        foreach ($padre->childNodes as $hijo) {
            if (! $hijo instanceof DOMElement || in_array($hijo->localName, $omitir, true)) {
                continue;
            }

            if ($repetidos[$hijo->localName] > 1) {
                $campos[$hijo->localName] = "({$repetidos[$hijo->localName]} elementos)";

                continue;
            }

            $nietos = array_filter(iterator_to_array($hijo->childNodes), fn ($n) => $n instanceof DOMElement);

            if ($nietos === []) {
                $campos[$hijo->localName] = $hijo->getAttribute('xsi:nil') === 'true' ? '(nil)' : $hijo->textContent;

                continue;
            }

            foreach ($this->aplanar($hijo) as $clave => $valor) {
                $campos["{$hijo->localName}.{$clave}"] = $valor;
            }
        }

        return $campos;
    }

    private function archivoBinario(): ?string
    {
        $base64 = $this->peticionXpath()?->query("//*[local-name()='archivo']")->item(0)?->textContent;

        if (blank($base64)) {
            return null;
        }

        $binario = base64_decode(trim($base64), true);

        return $binario === false ? null : $binario;
    }

    /**
     * @return list<array{nombre: string, xml: string}>
     */
    private function leerTar(string $tar): array
    {
        $documentos = [];
        $posicion = 0;

        while ($posicion + 512 <= strlen($tar)) {
            $cabecera = substr($tar, $posicion, 512);
            $nombre = rtrim(substr($cabecera, 0, 100), "\0");

            if ($nombre === '') {
                break;
            }

            $tamanio = (int) octdec(rtrim(substr($cabecera, 124, 12), "\0 "));
            $documentos[] = ['nombre' => $nombre, 'xml' => substr($tar, $posicion + 512, $tamanio)];
            $posicion += 512 + (int) ceil($tamanio / 512) * 512;
        }

        return $documentos;
    }

    private function peticionXpath(): ?DOMXPath
    {
        return $this->peticion ??= $this->xpath($this->log->xml_enviado);
    }

    private function respuestaXpath(): ?DOMXPath
    {
        return $this->respuestaXml ??= $this->xpath($this->log->xml_recibido);
    }

    private function xpath(?string $xml): ?DOMXPath
    {
        if (blank($xml)) {
            return null;
        }

        $doc = new DOMDocument;

        return @$doc->loadXML(trim($xml), LIBXML_NONET | LIBXML_PARSEHUGE) ? new DOMXPath($doc) : null;
    }
}
