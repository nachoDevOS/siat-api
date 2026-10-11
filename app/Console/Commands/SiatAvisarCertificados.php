<?php

namespace App\Console\Commands;

use App\Models\Certificado;
use App\Models\Empresa;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

#[Signature('siat:avisar-certificados')]
#[Description('Alerta los certificados y tokens delegados que vencen en menos de 30 dias (diario)')]
class SiatAvisarCertificados extends Command
{
    private const DIAS_DE_AVISO = 30;

    /**
     * Chequeo diario (seccion 8.6) de las dos credenciales que, al vencer,
     * cortan TODA la facturacion de un cliente:
     *   - el certificado .p12: sin el no se firma;
     *   - el token delegado del SIN: sin el ninguna llamada SOAP autentica.
     *
     * Cada alerta va tambien al log: lo programado corre sin consola y lo que
     * solo se imprime se pierde.
     */
    public function handle(): int
    {
        $limite = now()->addDays(self::DIAS_DE_AVISO);
        $alertas = 0;

        $certificados = Certificado::where('activo', true)
            ->whereNotNull('vence_el')
            ->where('vence_el', '<=', $limite)
            ->with('empresa')
            ->get();

        foreach ($certificados as $certificado) {
            $this->alertar("Certificado de {$certificado->empresa->nombre_comercial}", $certificado->vence_el->copy()->endOfDay());
            $alertas++;
        }

        foreach (Empresa::whereNotNull('token_delegado')->get() as $empresa) {
            $vence = $empresa->vencimientoTokenDelegado();

            if ($vence !== null && $vence->lessThanOrEqualTo($limite)) {
                $this->alertar("Token delegado de {$empresa->nombre_comercial}", $vence);
                $alertas++;
            }
        }

        $this->info("Credenciales vencidas o por vencer: {$alertas}");

        return self::SUCCESS;
    }

    private function alertar(string $que, Carbon $vence): void
    {
        $mensaje = $vence->isPast()
            ? "{$que} VENCIO el {$vence->format('d/m/Y')}: el cliente no puede facturar."
            : "{$que} vence el {$vence->format('d/m/Y')} (".(int) now()->diffInDays($vence).' dias).';

        $this->warn($mensaje);
        Log::warning($mensaje);
    }
}
