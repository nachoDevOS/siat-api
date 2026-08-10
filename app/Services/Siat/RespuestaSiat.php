<?php

namespace App\Services\Siat;

/**
 * Lee la respuesta de una operacion del SIN.
 *
 * Existe porque el SIN NO usa un SoapFault para rechazar un documento: si el
 * hash no cuadra, la firma no valida o el XML esta fuera de orden, responde
 * HTTP 200 con 'transaccion' en false y el motivo dentro de 'mensajesList'.
 *
 * Sin esta lectura, un rechazo se veia igual que una aceptacion: la factura se
 * marcaba ENVIADA con el codigo de recepcion vacio y el motivo se descartaba.
 */
class RespuestaSiat
{
    /**
     * @param  list<array{codigo: int|string, descripcion: string}>  $mensajes
     * @param  mixed  $crudo  cuerpo de la respuesta ya desenvuelto del nodo
     *                        raiz, para leer los campos propios de cada
     *                        operacion (codigoPuntoVenta, fechaHora, ...).
     */
    private function __construct(
        public readonly bool $aceptada,
        public readonly ?string $codigoRecepcion,
        public readonly ?string $codigoEstado,
        public readonly ?string $descripcion,
        public readonly array $mensajes,
        public readonly mixed $crudo = null,
    ) {}

    /**
     * Interpreta la respuesta cruda del SoapClient.
     *
     * @param  string  $raiz  nodo que envuelve la respuesta de la operacion.
     */
    public static function desde(mixed $respuesta, string $raiz = 'RespuestaServicioFacturacion'): self
    {
        $cuerpo = data_get($respuesta, $raiz) ?? $respuesta;

        $transaccion = data_get($cuerpo, 'transaccion');
        $codigoRecepcion = data_get($cuerpo, 'codigoRecepcion');

        return new self(
            // 'transaccion' es la palabra final del SIN. Si no viene (respuestas
            // viejas o de otra operacion), se acepta cuando hay codigo de
            // recepcion, que es la unica senal de que el envio entro.
            aceptada: $transaccion === null
                ? filled($codigoRecepcion)
                : filter_var($transaccion, FILTER_VALIDATE_BOOLEAN),
            codigoRecepcion: $codigoRecepcion === null ? null : (string) $codigoRecepcion,
            codigoEstado: ($estado = data_get($cuerpo, 'codigoEstado')) === null ? null : (string) $estado,
            descripcion: ($desc = data_get($cuerpo, 'codigoDescripcion')) === null ? null : (string) $desc,
            mensajes: self::mensajes($cuerpo),
            crudo: $cuerpo,
        );
    }

    /**
     * Motivos del rechazo, en una linea, para el log y el mensaje de error.
     */
    public function motivo(): string
    {
        if ($this->mensajes === []) {
            return $this->descripcion ?? 'El SIN rechazo el envio sin detallar el motivo.';
        }

        return implode(' | ', array_map(
            fn (array $m): string => "[{$m['codigo']}] {$m['descripcion']}",
            $this->mensajes,
        ));
    }

    /**
     * Normaliza 'mensajesList', que SOAP entrega como objeto suelto cuando hay
     * un solo mensaje y como arreglo cuando hay varios.
     *
     * @return list<array{codigo: int|string, descripcion: string}>
     */
    private static function mensajes(mixed $cuerpo): array
    {
        $lista = data_get($cuerpo, 'mensajesList') ?? [];

        if (is_object($lista) || (is_array($lista) && array_key_exists('descripcion', $lista))) {
            $lista = [$lista];
        }

        return array_values(array_map(
            fn (mixed $m): array => [
                'codigo' => data_get($m, 'codigo') ?? '',
                'descripcion' => (string) (data_get($m, 'descripcion') ?? ''),
            ],
            (array) $lista,
        ));
    }
}
