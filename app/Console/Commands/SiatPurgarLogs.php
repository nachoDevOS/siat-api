<?php

namespace App\Console\Commands;

use App\Models\LogSiat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('siat:purgar-logs {--dias= : Dias de retencion, por defecto config(siat.retencion_logs_dias)}')]
#[Description('Borra la auditoria SOAP mas vieja que el periodo de retencion')]
class SiatPurgarLogs extends Command
{
    /**
     * logs_siat guarda el XML completo de cada llamada, que incluye los datos
     * del comprador. Sin poda la tabla crece sin techo y conserva datos
     * personales mucho mas alla de lo necesario para depurar.
     *
     * Se borra en lotes para no bloquear la tabla en una sola sentencia.
     */
    public function handle(): int
    {
        $dias = (int) ($this->option('dias') ?? config('siat.retencion_logs_dias'));

        if ($dias < 1) {
            $this->error('Los dias de retencion deben ser al menos 1.');

            return self::FAILURE;
        }

        $corte = now()->subDays($dias);
        $borrados = 0;

        do {
            $lote = LogSiat::where('created_at', '<', $corte)->limit(1000)->delete();
            $borrados += $lote;
        } while ($lote > 0);

        $this->info("Logs SIAT borrados (anteriores a {$corte->toDateString()}): {$borrados}");

        return self::SUCCESS;
    }
}
