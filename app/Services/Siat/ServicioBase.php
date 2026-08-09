<?php

namespace App\Services\Siat;

use App\Exceptions\SiatException;
use App\Models\Empresa;
use App\Models\LogSiat;
use SoapFault;
use Throwable;

/**
 * Base comun de todos los servicios SOAP del SIAT.
 *
 * Concentra tres cosas que se repiten en cada operacion:
 *   1. La cabecera de campos que casi toda peticion lleva (seccion 7.2).
 *   2. La invocacion real del metodo SOAP.
 *   3. La auditoria: guardar en logs_siat el XML enviado, el recibido y el
 *      tiempo, exito o falla.
 *
 * Las clases hijas solo definen operaciones concretas y arman su payload;
 * no vuelven a tocar transporte ni logging.
 */
abstract class ServicioBase
{
    public function __construct(
        protected readonly Empresa $empresa,
        protected readonly SiatClient $cliente,
    ) {}

    /**
     * Cabecera de campos presentes en casi toda operacion del SIN.
     * Las hijas la usan como punto de partida y agregan lo especifico.
     *
     * @return array<string, mixed>
     */
    protected function solicitudBase(): array
    {
        return [
            'codigoAmbiente' => $this->empresa->codigo_ambiente,
            'codigoSistema' => $this->empresa->codigo_sistema,
            'codigoModalidad' => $this->empresa->codigo_modalidad,
            'nit' => (int) $this->empresa->nit,
        ];
    }

    /**
     * Invoca una operacion SOAP y la audita en logs_siat.
     *
     * @param  string  $claveServicio  clave de config('siat.servicios').
     * @param  string  $operacion  nombre EXACTO del metodo del WSDL. Verificar
     *                             contra el WSDL vigente antes de usar (rule 7).
     * @param  array<string, mixed>  $parametros
     *
     * @throws SiatException si el SIAT responde con falla o no esta disponible.
     */
    protected function invocar(string $claveServicio, string $operacion, array $parametros): mixed
    {
        $servicioNombre = config("siat.servicios.{$claveServicio}");
        $inicio = microtime(true);

        try {
            $soap = $this->cliente->paraServicio($claveServicio);

            // El SIN espera los parametros envueltos en una clave con el nombre
            // de la operacion; verificar el nombre del wrapper contra el WSDL.
            // Se llama via el cliente para que aplique el timeout de lectura.
            $respuesta = $this->cliente->llamar($soap, $operacion, $parametros);

            $this->registrarLog($servicioNombre, $operacion, $inicio, true);

            return $respuesta;
        } catch (SoapFault $e) {
            $this->registrarLog($servicioNombre, $operacion, $inicio, false, $e->getMessage());

            throw new SiatException(
                "Falla en la operacion '{$operacion}': {$e->getMessage()}",
                xmlEnviado: $this->cliente->ultimaPeticionXml(),
                xmlRecibido: $this->cliente->ultimaRespuestaXml(),
                previous: $e,
            );
        } catch (Throwable $e) {
            $this->registrarLog($servicioNombre, $operacion, $inicio, false, $e->getMessage());

            throw new SiatException("Error inesperado en '{$operacion}': {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * Guarda la evidencia de la llamada. Nunca debe romper el flujo principal:
     * si el log falla, se ignora para no perder la operacion en si.
     */
    private function registrarLog(
        string $servicio,
        string $operacion,
        float $inicio,
        bool $exitoso,
        ?string $error = null,
    ): void {
        try {
            LogSiat::create([
                'empresa_id' => $this->empresa->id,
                'servicio' => $servicio,
                'operacion' => $operacion,
                'xml_enviado' => $this->cliente->ultimaPeticionXml(),
                'xml_recibido' => $this->cliente->ultimaRespuestaXml(),
                'duracion_ms' => (int) ((microtime(true) - $inicio) * 1000),
                'exitoso' => $exitoso,
                'mensaje_error' => $error,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Auditoria best-effort: no interrumpe la operacion del SIN.
        }
    }
}
