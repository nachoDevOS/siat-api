<?php

namespace App\Console\Commands;

use App\Exceptions\SiatException;
use App\Models\Empresa;
use App\Services\Siat\FabricaServicios;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('siat:inspeccionar-wsdl
    {empresa : ID de la empresa cuyo ambiente y token se usan}
    {--servicio=* : Claves de config(siat.servicios) a inspeccionar; por defecto, todas}
    {--tipos : Listar tambien los tipos complejos declarados en el WSDL}')]
#[Description('Descarga el WSDL del SIAT y lista sus operaciones y tipos. Solo reporta, no cambia nada')]
class SiatInspeccionarWsdl extends Command
{
    /**
     * Herramienta de contraste, no de operacion.
     *
     * Buena parte de los nombres de operacion y de campo que usa el sistema
     * (ConstructorXml, ServicioFacturacion, ServicioCodigos, ArmadorPaquete)
     * estan tomados de la documentacion, no del WSDL real, porque todavia no
     * hay acceso al ambiente del SIN. Este comando existe para poder
     * contrastarlos el dia que haya token de piloto.
     *
     * NO modifica nada: descarga el WSDL, lista lo que expone y termina.
     */
    public function handle(FabricaServicios $fabrica): int
    {
        $empresa = Empresa::find((int) $this->argument('empresa'));

        if ($empresa === null) {
            $this->error("No existe la empresa con id {$this->argument('empresa')}.");

            return self::FAILURE;
        }

        if (blank($empresa->token_delegado)) {
            $this->error('La empresa no tiene token delegado: el SIN no entregara el WSDL.');

            return self::FAILURE;
        }

        $servicios = $this->option('servicio') ?: array_keys((array) config('siat.servicios'));

        $this->info("Empresa: {$empresa->nombre_comercial} (NIT {$empresa->nit})");
        $this->line('Ambiente: '.($empresa->codigo_ambiente === 1 ? 'Produccion' : 'Piloto'));

        $cliente = $fabrica->cliente($empresa);
        $fallos = 0;

        foreach ($servicios as $claveServicio) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=cyan>Servicio</>', $claveServicio);

            try {
                $this->line($cliente->urlWsdl($claveServicio));
                $soap = $cliente->paraServicio($claveServicio);
            } catch (SiatException $e) {
                $this->error($e->getMessage());
                $fallos++;

                continue;
            }

            $this->reportarOperaciones($soap->__getFunctions());

            if ($this->option('tipos')) {
                $this->reportarTipos($soap->__getTypes());
            }
        }

        $this->newLine();
        $this->line('Contrastar estos nombres contra los que usan ConstructorXml, ServicioFacturacion,');
        $this->line('ServicioCodigos y ArmadorPaquete antes de dar por buena la integracion.');

        return $fallos > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<string>  $operaciones  tal como las devuelve __getFunctions().
     */
    private function reportarOperaciones(array $operaciones): void
    {
        $this->line('Operaciones ('.count($operaciones).'):');

        foreach ($operaciones as $operacion) {
            $this->line('  · '.$operacion);
        }
    }

    /**
     * @param  list<string>  $tipos  tal como los devuelve __getTypes().
     */
    private function reportarTipos(array $tipos): void
    {
        $this->newLine();
        $this->line('Tipos ('.count($tipos).'):');

        foreach ($tipos as $tipo) {
            // __getTypes() devuelve la estructura completa en varias lineas.
            foreach (explode("\n", $tipo) as $linea) {
                $this->line('  '.$linea);
            }
        }
    }
}
