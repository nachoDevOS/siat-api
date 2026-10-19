<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\EntregarWebhook;
use App\Models\Empresa;
use App\Models\WebhookEndpoint;
use App\Models\WebhookEntrega;
use App\Services\Webhooks\DestinoWebhook;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Destinos de webhook que el sistema del cliente administra solo: varios por
 * empresa, cada uno con sus eventos y su secreto, con historial de entregas y
 * reenvio manual para depurar el receptor.
 */
class WebhookController extends Controller
{
    /**
     * GET /api/v1/webhooks
     */
    public function index(Request $request): JsonResponse
    {
        $destinos = WebhookEndpoint::where('empresa_id', $this->empresa($request)->id)->orderBy('id')->get();

        return response()->json([
            'exito' => true,
            'webhooks' => $destinos->map(fn (WebhookEndpoint $d) => $this->forma($d))->all(),
            'eventos_disponibles' => config('siat.webhooks.eventos'),
        ]);
    }

    /**
     * POST /api/v1/webhooks — el secreto se muestra solo en esta respuesta.
     */
    public function store(Request $request): JsonResponse
    {
        $empresa = $this->empresa($request);

        if (WebhookEndpoint::where('empresa_id', $empresa->id)->count() >= WebhookEndpoint::MAXIMO_POR_EMPRESA) {
            return response()->json([
                'exito' => false,
                'error' => 'LIMITE_WEBHOOKS',
                'mensaje' => 'Se alcanzo el maximo de '.WebhookEndpoint::MAXIMO_POR_EMPRESA.' webhooks por empresa.',
            ], Response::HTTP_CONFLICT);
        }

        $datos = $request->validate($this->reglas(obligatorio: true));
        $secreto = WebhookEndpoint::nuevoSecreto();

        $destino = WebhookEndpoint::create($datos + [
            'empresa_id' => $empresa->id,
            'secreto' => $secreto,
            'activo' => true,
        ]);

        return response()->json([
            'exito' => true,
            'webhook' => $this->forma($destino) + ['secreto' => $secreto],
            'mensaje' => 'Guarde el secreto ahora: no se vuelve a mostrar.',
        ], Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/webhooks/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['exito' => true, 'webhook' => $this->forma($this->buscar($request, $id))]);
    }

    /**
     * PUT /api/v1/webhooks/{id} — cambia url, eventos, descripcion o activo.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $destino = $this->buscar($request, $id);

        $destino->update($request->validate($this->reglas(obligatorio: false) + [
            'activo' => ['sometimes', 'boolean'],
        ]));

        return response()->json(['exito' => true, 'webhook' => $this->forma($destino)]);
    }

    /**
     * DELETE /api/v1/webhooks/{id} — borra el destino y su historial.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->buscar($request, $id)->delete();

        return response()->json(['exito' => true, 'mensaje' => 'Webhook eliminado.']);
    }

    /**
     * POST /api/v1/webhooks/{id}/rotar-secreto — el anterior deja de firmar
     * en el acto; las entregas pendientes salen con el nuevo.
     */
    public function rotarSecreto(Request $request, int $id): JsonResponse
    {
        $destino = $this->buscar($request, $id);
        $secreto = WebhookEndpoint::nuevoSecreto();
        $destino->update(['secreto' => $secreto]);

        return response()->json([
            'exito' => true,
            'webhook' => $this->forma($destino) + ['secreto' => $secreto],
            'mensaje' => 'Guarde el secreto ahora: no se vuelve a mostrar.',
        ]);
    }

    /**
     * GET /api/v1/webhooks/{id}/entregas — historial, lo mas nuevo primero.
     */
    public function entregas(Request $request, int $id): JsonResponse
    {
        $destino = $this->buscar($request, $id);

        $filtros = $request->validate([
            'estado' => ['nullable', Rule::in([WebhookEntrega::ESTADO_PENDIENTE, WebhookEntrega::ESTADO_ENTREGADA, WebhookEntrega::ESTADO_FALLIDA])],
        ]);

        $entregas = $destino->entregas()
            ->when($filtros['estado'] ?? null, fn ($q, $estado) => $q->where('estado', $estado))
            ->latest('id')
            ->paginate(50);

        return response()->json([
            'exito' => true,
            'entregas' => collect($entregas->items())->map(fn (WebhookEntrega $e) => [
                'id' => $e->uuid,
                'evento' => $e->evento,
                'estado' => $e->estado,
                'intentos' => $e->intentos,
                'codigo_http' => $e->codigo_http,
                'error' => $e->error,
                'creada_en' => $e->created_at?->toIso8601String(),
                'entregada_en' => $e->entregada_en?->toIso8601String(),
                'cuerpo' => json_decode($e->cuerpo, true),
            ])->all(),
            'paginacion' => [
                'total' => $entregas->total(),
                'pagina' => $entregas->currentPage(),
                'por_pagina' => $entregas->perPage(),
            ],
        ]);
    }

    /**
     * POST /api/v1/webhooks/entregas/{uuid}/reenviar — vuelve a mandar el
     * mismo cuerpo (mismo id de entrega), entregada o fallida.
     */
    public function reenviar(Request $request, string $uuid): JsonResponse
    {
        $entrega = WebhookEntrega::where('uuid', $uuid)
            ->whereHas('endpoint', fn ($q) => $q->where('empresa_id', $this->empresa($request)->id))
            ->firstOrFail();

        $entrega->update(['estado' => WebhookEntrega::ESTADO_PENDIENTE, 'error' => null]);
        EntregarWebhook::dispatch($entrega->id);

        return response()->json(['exito' => true, 'mensaje' => 'Reenvio encolado.', 'id' => $entrega->uuid], Response::HTTP_ACCEPTED);
    }

    /**
     * @return array<string, mixed>
     */
    private function reglas(bool $obligatorio): array
    {
        $requerido = $obligatorio ? 'required' : 'sometimes';
        $validos = array_merge(['*'], array_keys(config('siat.webhooks.eventos')), $this->grupos());

        return [
            // El destino lo elige el cliente: se valida contra SSRF al
            // guardar para que vea el error ya, no por avisos mudos.
            'url' => [$requerido, 'url', 'max:255', function (string $atributo, mixed $valor, Closure $falla) {
                $motivo = app(DestinoWebhook::class)->motivoRechazo((string) $valor);

                if ($motivo !== null) {
                    $falla($motivo);
                }
            }],
            'eventos' => [$requerido, 'array', 'min:1'],
            'eventos.*' => ['string', Rule::in($validos)],
            'descripcion' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * 'factura.*', 'siat.*'... a partir de los eventos del catalogo.
     *
     * @return list<string>
     */
    private function grupos(): array
    {
        return collect(array_keys(config('siat.webhooks.eventos')))
            ->map(fn (string $evento) => strtok($evento, '.').'.*')
            ->unique()->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function forma(WebhookEndpoint $destino): array
    {
        return [
            'id' => $destino->id,
            'url' => $destino->url,
            'eventos' => $destino->eventos,
            'descripcion' => $destino->descripcion,
            'activo' => $destino->activo,
            'creado_en' => $destino->created_at?->toIso8601String(),
        ];
    }

    private function buscar(Request $request, int $id): WebhookEndpoint
    {
        return WebhookEndpoint::where('empresa_id', $this->empresa($request)->id)->findOrFail($id);
    }

    private function empresa(Request $request): Empresa
    {
        return $request->attributes->get('empresa');
    }
}
