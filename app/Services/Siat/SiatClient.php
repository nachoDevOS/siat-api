<?php

namespace App\Services\Siat;

use App\Exceptions\SiatException;
use App\Models\Empresa;
use SoapClient;
use SoapFault;

/**
 * Unico punto del sistema que construye un SoapClient contra el SIAT.
 *
 * Encapsula tres cosas que todos los servicios SOAP necesitan igual:
 *   1. Resolver la URL del WSDL segun el ambiente de la empresa.
 *   2. Meter el token delegado en la cabecera HTTP (no va en el cuerpo).
 *   3. Aplicar timeouts, traza y cache de WSDL de forma consistente.
 *
 * Regla de frontera: nadie fuera de app/Services/Siat/ debe crear un
 * SoapClient. Si el SIN cambia algo del transporte, se toca solo este archivo.
 */
class SiatClient
{
    /**
     * Ultimo cliente SOAP construido. Se guarda para poder leer con
     * __getLastRequest() / __getLastResponse() el XML real que viajo,
     * que es lo que se audita en logs_siat.
     */
    private ?SoapClient $ultimoCliente = null;

    public function __construct(private readonly Empresa $empresa) {}

    /**
     * Devuelve un SoapClient listo para invocar operaciones de un servicio.
     *
     * @param  string  $claveServicio  clave definida en config('siat.servicios'),
     *                                 por ejemplo 'codigos' o 'sincronizacion'.
     *
     * @throws SiatException si el WSDL no se puede cargar (SIAT caido, token
     *                       invalido o servicio mal configurado).
     */
    public function paraServicio(string $claveServicio): SoapClient
    {
        $wsdl = $this->urlWsdl($claveServicio);

        try {
            $cliente = new SoapClient($wsdl, $this->opciones());
        } catch (SoapFault $e) {
            // Envolvemos el SoapFault en nuestra excepcion para que el resto
            // del sistema no dependa de la clase de SOAP directamente.
            throw new SiatException(
                "No se pudo cargar el WSDL del servicio '{$claveServicio}': {$e->getMessage()}",
                previous: $e,
            );
        }

        $this->ultimoCliente = $cliente;

        return $cliente;
    }

    /**
     * Arma la URL del WSDL: {base_del_ambiente}/{NombreServicio}?wsdl
     */
    public function urlWsdl(string $claveServicio): string
    {
        $servicio = config("siat.servicios.{$claveServicio}");

        if ($servicio === null) {
            throw new SiatException("Servicio SIAT desconocido: '{$claveServicio}'.");
        }

        $base = config("siat.urls.{$this->empresa->codigo_ambiente}");

        if ($base === null) {
            throw new SiatException(
                "Ambiente SIAT sin URL configurada: {$this->empresa->codigo_ambiente}.",
            );
        }

        return rtrim($base, '/')."/{$servicio}?wsdl";
    }

    /**
     * XML de la ultima peticion enviada. Null si aun no se invoco nada o si
     * la traza esta desactivada.
     */
    public function ultimaPeticionXml(): ?string
    {
        return $this->ultimoCliente?->__getLastRequest();
    }

    /**
     * XML de la ultima respuesta recibida.
     */
    public function ultimaRespuestaXml(): ?string
    {
        return $this->ultimoCliente?->__getLastResponse();
    }

    /**
     * Opciones del SoapClient comunes a todos los servicios.
     *
     * El token va en la cabecera "Authorization: Token {token}" mediante un
     * stream_context, porque el SIAT lo exige en el transporte HTTP, no en
     * el sobre SOAP.
     *
     * @return array<string, mixed>
     */
    private function opciones(): array
    {
        $contexto = stream_context_create([
            'http' => [
                'header' => "Authorization: Token {$this->empresa->token_delegado}",
            ],
        ]);

        return [
            'trace' => config('siat.trace'),
            // Queremos que un error del SIN lance SoapFault y no devuelva null.
            'exceptions' => true,
            'connection_timeout' => config('siat.connection_timeout'),
            'cache_wsdl' => config('siat.wsdl_cache'),
            'stream_context' => $contexto,
            // Timeout de lectura del socket (segundos) para no colgar al worker.
            'default_socket_timeout' => config('siat.timeout'),
        ];
    }
}
