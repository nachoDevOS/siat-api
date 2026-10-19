<?php

namespace App\Mcp\Tools;

use App\Models\Factura;
use App\Services\Factura\AnuladorFactura;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Anula una factura o nota ante el SIN (hasta el dia 9 del mes siguiente). motivo sale del catalogo motivos_anulacion. El SIN confirma en segundo plano.')]
class AnularFactura extends HerramientaSiat
{
    public function handle(Request $request, AnuladorFactura $anulador): Response
    {
        if ($error = $this->sinPermiso('facturas:anular')) {
            return $error;
        }

        $factura = $this->buscarFactura((string) $request->get('cuf'));

        if ($factura === null) {
            return Response::error('No existe una factura de la empresa con ese CUF.');
        }

        $motivo = (int) $request->get('motivo');

        if (! array_key_exists((string) $motivo, $anulador->motivos())) {
            return Response::error('El motivo no esta en el catalogo motivos_anulacion del SIN.');
        }

        if ($factura->estado === Factura::ESTADO_ANULADA) {
            return $this->json(['mensaje' => 'La factura ya estaba anulada.', 'cuf' => $factura->cuf]);
        }

        if (! $anulador->esAnulable($factura)) {
            return Response::error($anulador->motivoNoAnulable($factura));
        }

        $anulador->anular($factura, $motivo);

        return $this->json(['mensaje' => 'Anulacion registrada. Pendiente de confirmacion del SIAT.', 'cuf' => $factura->cuf]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'cuf' => $schema->string()->required(),
            'motivo' => $schema->integer()->description('Codigo del catalogo motivos_anulacion.')->required(),
        ];
    }
}
