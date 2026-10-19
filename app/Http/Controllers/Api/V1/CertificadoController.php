<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Certificado;
use App\Models\Empresa;
use App\Services\Factura\CargadorCertificado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Certificado digital de la empresa, administrado por el propio cliente: lo
 * renueva sin pedirselo al proveedor. Nunca se devuelve el certificado ni su
 * contrasena, solo su estado.
 */
class CertificadoController extends Controller
{
    /**
     * GET /api/v1/certificado — estado del certificado activo.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'exito' => true,
            'certificado' => $this->forma($this->empresa($request)->certificadoActivo()->first()),
        ]);
    }

    /**
     * POST /api/v1/certificado — carga un .p12 nuevo (multipart: archivo +
     * passphrase) y lo deja activo. Si no abre, el anterior sigue activo.
     */
    public function store(Request $request, CargadorCertificado $cargador): JsonResponse
    {
        $datos = $request->validate([
            'archivo' => ['required', 'file', 'max:5120'],
            'passphrase' => ['required', 'string'],
        ]);

        $certificado = $cargador->cargar(
            $this->empresa($request),
            (string) file_get_contents($datos['archivo']->getRealPath()),
            $datos['passphrase'],
        );

        return response()->json([
            'exito' => true,
            'mensaje' => 'Certificado verificado y activado.',
            'certificado' => $this->forma($certificado),
        ], 201);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function forma(?Certificado $certificado): ?array
    {
        if ($certificado === null) {
            return null;
        }

        return [
            'vence_el' => $certificado->vence_el?->toDateString(),
            'vigente' => ! $certificado->estaVencido(),
            'dias_restantes' => $certificado->vence_el === null
                ? null
                : max(0, (int) now()->startOfDay()->diffInDays($certificado->vence_el->copy()->startOfDay(), false)),
            // Un autofirmado solo sirve en el piloto.
            'de_prueba' => $certificado->esDePrueba(),
            'cargado_en' => $certificado->created_at?->toIso8601String(),
        ];
    }

    private function empresa(Request $request): Empresa
    {
        return $request->attributes->get('empresa');
    }
}
