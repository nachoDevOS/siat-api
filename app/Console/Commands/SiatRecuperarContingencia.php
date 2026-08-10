<?php

namespace App\Console\Commands;

use App\Exceptions\SiatException;
use App\Jobs\EnviarPaqueteContingencia;
use App\Models\EventoSignificativo;
use App\Services\Contingencia\GestorContingencia;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\RespuestaSiat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Cierra los eventos de contingencia abiertos cuando el SIAT vuelve, y despacha
 * el paquete con las facturas acumuladas.
 *
 * Sin este comando la contingencia era de ida y no de vuelta: una factura que
 * caia a CONTINGENCIA se quedaba ahi para siempre, porque el unico lugar que
 * llamaba a recuperar() era el paso 16 del piloto. La factura seguia siendo
 * valida, pero el SIN nunca llegaba a verla.
 */
#[Signature('siat:recuperar-contingencia')]
#[Description('Cierra los eventos de contingencia cuyo SIAT ya respondio y envia sus paquetes')]
class SiatRecuperarContingencia extends Command
{
    public function __construct(
        private readonly GestorContingencia $contingencia,
        private readonly FabricaServicios $fabrica,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $eventos = EventoSignificativo::with(['empresa', 'puntoVenta.sucursal'])
            ->where('estado', 'ABIERTO')
            ->get();

        if ($eventos->isEmpty()) {
            $this->info('No hay eventos de contingencia abiertos.');

            return self::SUCCESS;
        }

        $recuperados = 0;

        foreach ($eventos as $evento) {
            if ($this->recuperar($evento)) {
                $recuperados++;
            }
        }

        $this->info("Eventos cerrados y paquetes despachados: {$recuperados} de {$eventos->count()}.");

        return self::SUCCESS;
    }

    /**
     * Intenta recuperar un evento. Devuelve false si el SIAT sigue caido.
     */
    private function recuperar(EventoSignificativo $evento): bool
    {
        if (! $this->siatResponde($evento)) {
            $this->line("Evento {$evento->id}: el SIAT sigue sin responder.");

            return false;
        }

        // El SIN exige que el evento quede registrado de su lado antes de
        // recibir el paquete: el codigo de recepcion del evento es lo que
        // justifica que esas facturas se emitieran fuera de linea.
        if (! $this->registrarEnSiat($evento)) {
            return false;
        }

        $paquete = $this->contingencia->recuperar($evento);

        if ($paquete === null) {
            $this->line("Evento {$evento->id}: cerrado, sin facturas pendientes de enviar.");

            return true;
        }

        EnviarPaqueteContingencia::dispatch($paquete->id);

        $this->line("Evento {$evento->id}: paquete {$paquete->id} con {$paquete->cantidad_facturas} facturas despachado.");

        return true;
    }

    /**
     * Sonda barata para saber si el SIAT volvio.
     *
     * Se usa verificarComunicacion y no sincronizarFechaHora porque el WSDL
     * declara 'struct verificarComunicacion { }': es la UNICA operacion del SIN
     * que no pide ningun parametro. La fecha/hora comparte struct con las
     * parametricas y exige cuis, sucursal y punto de venta, lo que ataria la
     * sonda a que el punto de venta tenga CUIS vigente.
     */
    private function siatResponde(EventoSignificativo $evento): bool
    {
        try {
            $this->fabrica->codigos($evento->empresa)->verificarComunicacion();

            return true;
        } catch (SiatException) {
            return false;
        }
    }

    /**
     * Registra el evento significativo en el SIN y guarda su codigo de
     * recepcion. Si el SIN lo rechaza, el evento queda abierto para reintentar
     * en la corrida siguiente: enviar el paquete sin el evento registrado solo
     * conseguiria que el SIN lo rechace tambien.
     */
    private function registrarEnSiat(EventoSignificativo $evento): bool
    {
        // Ya registrado en una corrida anterior que fallo mas adelante.
        if (filled($evento->codigo_recepcion)) {
            return true;
        }

        try {
            $respuesta = RespuestaSiat::desde(
                $this->fabrica->operaciones($evento->empresa)->registrarEvento([
                    'codigoSucursal' => $evento->puntoVenta->sucursal->codigo_sucursal,
                    'codigoPuntoVenta' => $evento->puntoVenta->codigo_punto_venta,
                    'codigoMotivoEvento' => $evento->codigo_evento,
                    'descripcion' => $evento->descripcion,
                    'cufdEvento' => $evento->cufd_evento,
                    'fechaHoraInicioEvento' => $evento->fecha_inicio?->format('Y-m-d\TH:i:s.v'),
                    'fechaHoraFinEvento' => now()->format('Y-m-d\TH:i:s.v'),
                ]),
            );
        } catch (SiatException $e) {
            $this->warn("Evento {$evento->id}: fallo el registro en el SIN ({$e->getMessage()}).");

            return false;
        }

        if (! $respuesta->aceptada) {
            $this->warn("Evento {$evento->id}: el SIN rechazo el registro ({$respuesta->motivo()}).");

            return false;
        }

        $evento->update(['codigo_recepcion' => $respuesta->codigoRecepcion]);

        return true;
    }
}
