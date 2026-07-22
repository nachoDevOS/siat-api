<?php

namespace App\Console\Commands;

use App\Models\Certificado;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('siat:avisar-certificados')]
#[Description('Alerta los certificados que vencen en menos de 30 dias (diario)')]
class SiatAvisarCertificados extends Command
{
    /**
     * Chequeo diario (seccion 8.6): un certificado vencido corta la firma y por
     * ende la facturacion, asi que se avisa con 30 dias de anticipacion.
     */
    public function handle(): int
    {
        $porVencer = Certificado::where('activo', true)
            ->whereNotNull('vence_el')
            ->whereBetween('vence_el', [now(), now()->addDays(30)])
            ->with('empresa')
            ->get();

        foreach ($porVencer as $certificado) {
            $dias = (int) now()->diffInDays($certificado->vence_el);
            $this->warn("Certificado de {$certificado->empresa->nombre_comercial} vence en {$dias} dias.");
        }

        $this->info("Certificados por vencer: {$porVencer->count()}");

        return self::SUCCESS;
    }
}
