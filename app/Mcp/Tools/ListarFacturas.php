<?php

namespace App\Mcp\Tools;

use App\Http\Resources\FacturaResource;
use App\Models\Factura;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Lista las ultimas facturas de la empresa (hasta 50), con filtros opcionales de fecha, estado, tipo o referencia externa.')]
class ListarFacturas extends HerramientaSiat
{
    public function handle(Request $request): Response
    {
        if ($error = $this->sinPermiso('facturas:leer')) {
            return $error;
        }

        $sectorNota = config('siat.codigos.nota_credito_debito.documento_sector');
        $tipo = $request->get('tipo', 'factura');

        $facturas = Factura::where('empresa_id', $this->empresa()->id)
            ->when($tipo === 'factura', fn ($q) => $q->where('codigo_documento_sector', '!=', $sectorNota))
            ->when($tipo === 'nota', fn ($q) => $q->where('codigo_documento_sector', $sectorNota))
            ->when($request->get('desde'), fn ($q, $desde) => $q->whereDate('fecha_emision', '>=', $desde))
            ->when($request->get('hasta'), fn ($q, $hasta) => $q->whereDate('fecha_emision', '<=', $hasta))
            ->when($request->get('estado'), fn ($q, $estado) => $q->where('estado', $estado))
            ->when($request->get('referencia_externa'), fn ($q, $ref) => $q->where('referencia_externa', $ref))
            ->with(['puntoVenta.sucursal', 'facturaOrigen', 'anulacion'])
            ->latest('fecha_emision')
            ->limit(50)
            ->get();

        return $this->json(FacturaResource::collection($facturas)->resolve());
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'desde' => $schema->string()->description('Fecha AAAA-MM-DD.'),
            'hasta' => $schema->string()->description('Fecha AAAA-MM-DD.'),
            'estado' => $schema->string()->enum(Factura::ESTADOS),
            'tipo' => $schema->string()->enum(['factura', 'nota', 'todos'])->description('Por defecto solo facturas.'),
            'referencia_externa' => $schema->string(),
        ];
    }
}
