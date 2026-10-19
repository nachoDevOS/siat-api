<?php

namespace App\Services\Panel;

use App\Exceptions\SiatException;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\GestorCodigos;
use App\Services\Siat\RespuestaSiat;

/**
 * Registra un punto de venta NUEVO en el SIAT y le pide sus codigos. Lo usan
 * el panel y la API (autogestion del cliente) con las mismas reglas.
 *
 * IRREVERSIBLE. El SIN crea uno nuevo en cada llamada y le asigna EL un
 * codigo —no se elige—; despues no se puede borrar, solo cerrar, y uno
 * cerrado no se reabre. Por eso no hay reintento automatico: si el SIN
 * responde pero la respuesta se pierde, el punto de venta quedo creado igual.
 */
class RegistradorPuntoVenta
{
    public function __construct(
        private readonly FabricaServicios $fabrica,
        private readonly PuntosVentaSiat $puntosVentaSiat,
        private readonly GestorCodigos $gestor,
    ) {}

    /**
     * @throws SiatException con el motivo listo para mostrar si no se creo (o
     *                       si no se sabe si se creo).
     */
    public function registrar(Sucursal $sucursal, string $nombre, int $tipo): PuntoVenta
    {
        try {
            $respuesta = RespuestaSiat::desde(
                $this->fabrica->operaciones($sucursal->empresa)->registrarPuntoVentaPara(
                    (int) $sucursal->codigo_sucursal,
                    $nombre,
                    $tipo,
                    $this->puntosVentaSiat->cuisParaConsultar($sucursal),
                ),
                'RespuestaRegistroPuntoVenta',
            );
        } catch (SiatException $e) {
            throw new SiatException('No se pudo registrar: '.$e->getMessage(), previous: $e);
        }

        if (! $respuesta->aceptada) {
            throw new SiatException('El SIN rechazo el registro: '.$respuesta->motivo());
        }

        $codigo = data_get($respuesta->crudo, 'codigoPuntoVenta');

        if (blank($codigo)) {
            // Peligroso: el SIN pudo haberlo creado igual. Se avisa para que se
            // consulte la lista en vez de reintentar a ciegas y duplicar.
            throw new SiatException('El SIN acepto el registro pero no devolvio el codigo. NO reintentes: consulta la lista del SIAT para ver si quedo creado.');
        }

        $puntoVenta = $sucursal->puntosVenta()->create([
            'codigo_punto_venta' => (int) $codigo,
            'nombre' => $nombre,
            'tipo_punto_venta' => $tipo,
            'siguiente_factura' => 1,
            'activo' => true,
            'registrado_en_siat' => now(),
        ]);

        // La lista cacheada quedo vieja: ahora hay uno mas del otro lado.
        $this->puntosVentaSiat->olvidar($sucursal);

        return $puntoVenta;
    }

    /**
     * Le pide CUIS y CUFD al SIN, best-effort.
     *
     * Si el SIAT no responde, el punto de venta igual queda configurado y se
     * reintenta despues: una caida del SIN no puede dejar el alta a medias.
     *
     * @return string resultado legible.
     */
    public function pedirCodigos(PuntoVenta $puntoVenta): string
    {
        try {
            $this->gestor->solicitarCuis($puntoVenta);
        } catch (SiatException $e) {
            return "El CUIS no se pudo pedir: {$e->getMessage()}";
        }

        try {
            $this->gestor->solicitarCufd($puntoVenta);
        } catch (SiatException $e) {
            return "CUIS obtenido. El CUFD no se pudo pedir: {$e->getMessage()}";
        }

        return 'Ya tiene CUIS y CUFD vigentes.';
    }
}
