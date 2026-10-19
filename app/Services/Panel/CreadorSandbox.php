<?php

namespace App\Services\Panel;

use App\Exceptions\SiatException;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use App\Services\Factura\GeneradorCertificadoPrueba;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Crea el sandbox de un cliente que ya factura en produccion: una copia de la
 * empresa en el ambiente PILOTO, para que su sistema de ventas pruebe cambios
 * emitiendo de verdad contra el SIAT piloto, sin validez fiscal y sin tocar
 * sus facturas reales.
 *
 * Copia lo que no depende del ambiente (NIT, razon social, codigo de sistema,
 * sucursales con su punto de venta 0) y deja afuera lo que si: el token
 * delegado del SIN (el de produccion no sirve en piloto), el webhook (las
 * pruebas no deben llegarle al sistema real) y los puntos de venta
 * registrados por API, que en el piloto no existen. Genera un certificado de
 * prueba, que en el piloto alcanza.
 */
class CreadorSandbox
{
    public function __construct(private readonly GeneradorCertificadoPrueba $certificados) {}

    /**
     * @throws RuntimeException si la empresa no admite sandbox.
     * @throws SiatException si no se pudo generar el certificado de prueba.
     */
    public function crear(Empresa $empresa): Empresa
    {
        if ($empresa->esSandbox()) {
            throw new RuntimeException('Esta empresa ya trabaja en el ambiente piloto: no necesita sandbox.');
        }

        $existente = Empresa::where('nit', $empresa->nit)
            ->where('codigo_ambiente', config('siat.codigos.ambiente.piloto'))
            ->exists();

        if ($existente) {
            throw new RuntimeException('Ya existe una empresa con este NIT en el ambiente piloto.');
        }

        return DB::transaction(function () use ($empresa) {
            $sandbox = $empresa->replicate(['sandbox_de_id', 'token_delegado', 'webhook_url']);
            $sandbox->fill([
                'sandbox_de_id' => $empresa->id,
                'nombre_comercial' => "{$empresa->nombre_comercial} (sandbox)",
                'codigo_ambiente' => config('siat.codigos.ambiente.piloto'),
                // Ya puede emitir por la API (Empresa::puedeEmitirPorApi) en
                // cuanto se le cargue el token delegado del piloto.
                'estado' => Empresa::ESTADO_EN_PRUEBAS,
            ])->save();

            foreach ($empresa->sucursales as $sucursal) {
                $copia = $sucursal->replicate()->fill(['empresa_id' => $sandbox->id]);
                $copia->save();

                // Solo el punto de venta implicito: el SIN lo da por existente
                // en cada sucursal, tambien en el piloto.
                PuntoVenta::create([
                    'sucursal_id' => $copia->id,
                    'codigo_punto_venta' => PuntoVenta::CODIGO_IMPLICITO,
                    'nombre' => 'Punto de venta 0',
                ]);
            }

            $this->certificados->generar($sandbox);

            return $sandbox;
        });
    }
}
