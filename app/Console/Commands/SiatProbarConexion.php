<?php

namespace App\Console\Commands;

use App\Exceptions\SiatException;
use App\Models\Empresa;
use App\Services\Siat\SiatClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('siat:probar {empresa : ID de la empresa a probar}')]
#[Description('Verifica que el WSDL del SIAT sea alcanzable con el token de la empresa')]
class SiatProbarConexion extends Command
{
    /**
     * Prueba de humo de la fase 1: confirma que, con el token de una empresa,
     * el sistema puede resolver y descargar el WSDL del SIAT. No emite nada;
     * solo valida el transporte (URL correcta, token presente, SIAT arriba).
     */
    public function handle(): int
    {
        $id = (int) $this->argument('empresa');

        $empresa = Empresa::find($id);

        if ($empresa === null) {
            $this->error("No existe la empresa con id {$id}.");

            return self::FAILURE;
        }

        $this->info("Empresa: {$empresa->nombre_comercial} (NIT {$empresa->nit})");

        $ambiente = $empresa->codigo_ambiente === 1 ? 'Produccion' : 'Piloto';
        $this->line("Ambiente: {$ambiente} (codigo {$empresa->codigo_ambiente})");

        if (blank($empresa->token_delegado)) {
            $this->error('La empresa no tiene token delegado cargado. Cargalo antes de probar.');

            return self::FAILURE;
        }

        $cliente = new SiatClient($empresa);

        // Probamos contra el servicio de codigos porque es el que expone
        // verificarComunicacion, la operacion mas liviana del SIN.
        $wsdl = $cliente->urlWsdl('codigos');
        $this->line("WSDL: {$wsdl}");

        try {
            $soap = $cliente->paraServicio('codigos');
        } catch (SiatException $e) {
            $this->error('Fallo la conexion con el SIAT:');
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // Si el WSDL cargo, listamos cuantas operaciones expone como evidencia
        // de que el contrato SOAP se leyo correctamente.
        $operaciones = $soap->__getFunctions();
        $this->newLine();
        $this->info('Conexion establecida. El WSDL respondio correctamente.');
        $this->line('Operaciones expuestas por el servicio: '.count($operaciones));

        return self::SUCCESS;
    }
}
