<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\SiatException;
use App\Http\Controllers\Controller;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Panel\PuntosVentaSiat;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\GestorCodigos;
use App\Services\Siat\RespuestaSiat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Alta local de puntos de venta y consulta de los que ya existen en el SIAT.
 *
 * Distincion importante: crear un punto de venta ACA no lo crea en el SIN. El
 * registro real lo hace el paso 10 del piloto, y es IRREVERSIBLE — el SIN
 * asigna un codigo nuevo en cada llamada y un punto de venta cerrado no se
 * reabre. Por eso existe la consulta: para ver que hay del otro lado antes de
 * registrar otro, y para importar el codigo real en vez de inventarlo.
 */
class PuntoVentaController extends Controller
{
    /**
     * Fuerza que se vuelva a preguntar al SIN que puntos de venta tiene esta
     * sucursal.
     *
     * La ficha ya muestra esa lista —la trae PuntosVentaSiat y la cachea 30
     * minutos— asi que este boton solo descarta lo cacheado. Se usa despues de
     * registrar un punto de venta nuevo, para no esperar a que venza el cache.
     */
    public function consultar(Sucursal $sucursal, PuntosVentaSiat $puntosVentaSiat): RedirectResponse
    {
        $puntosVentaSiat->olvidar($sucursal);

        return $this->volver($sucursal, 'Lista de puntos de venta actualizada desde el SIAT.');
    }

    /**
     * Registra un punto de venta NUEVO en el SIAT.
     *
     * IRREVERSIBLE. El SIN crea uno nuevo en cada llamada y le asigna EL un
     * codigo —no se elige—; despues no se puede borrar, solo cerrar, y uno
     * cerrado no se reabre. Por eso no hay reintento automatico ni se ofrece
     * junto a las acciones de uso diario: si el SIN responde pero la respuesta
     * se pierde, el punto de venta quedo creado igual.
     *
     * Antes de llegar aca conviene mirar la lista del SIN: casi siempre lo que
     * se busca ya existe y alcanza con «Configurar aca».
     */
    public function registrarEnSiat(
        Request $request,
        Sucursal $sucursal,
        FabricaServicios $fabrica,
        PuntosVentaSiat $puntosVentaSiat,
        GestorCodigos $gestor,
    ): RedirectResponse {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'tipo_punto_venta' => ['required', 'integer'],
        ]);

        try {
            $respuesta = RespuestaSiat::desde(
                $fabrica->operaciones($sucursal->empresa)->registrarPuntoVentaPara(
                    (int) $sucursal->codigo_sucursal,
                    $datos['nombre'],
                    (int) $datos['tipo_punto_venta'],
                    $puntosVentaSiat->cuisParaConsultar($sucursal),
                ),
                'RespuestaRegistroPuntoVenta',
            );
        } catch (SiatException $e) {
            return $this->volver($sucursal, 'No se pudo registrar: '.$e->getMessage());
        }

        if (! $respuesta->aceptada) {
            return $this->volver($sucursal, 'El SIN rechazo el registro: '.$respuesta->motivo());
        }

        $codigo = data_get($respuesta->crudo, 'codigoPuntoVenta');

        if (blank($codigo)) {
            // Peligroso: el SIN pudo haberlo creado igual. Se avisa para que se
            // consulte la lista en vez de reintentar a ciegas y duplicar.
            return $this->volver(
                $sucursal,
                'El SIN acepto el registro pero no devolvio el codigo. NO reintentes: consulta la lista del SIAT para ver si quedo creado.',
            );
        }

        $puntoVenta = $sucursal->puntosVenta()->create([
            'codigo_punto_venta' => (int) $codigo,
            'nombre' => $datos['nombre'],
            'tipo_punto_venta' => (int) $datos['tipo_punto_venta'],
            'siguiente_factura' => 1,
            'activo' => true,
            'registrado_en_siat' => now(),
        ]);

        // La lista cacheada quedo vieja: ahora hay uno mas del otro lado.
        $puntosVentaSiat->olvidar($sucursal);

        return $this->volver(
            $sucursal,
            "El SIN creo el punto de venta {$puntoVenta->codigo_punto_venta}. ".$this->pedirCodigos($gestor, $puntoVenta),
        );
    }

    /**
     * Configura en local un punto de venta que YA existe en el SIAT.
     *
     * Antes habia que crear uno local con un codigo cualquiera y despues
     * "adoptarle" el del SIN: dos pasos y un registro intermedio con un codigo
     * inventado. Aca se crea directamente con el codigo, el nombre y el tipo que
     * informo el SIN, y se le piden CUIS y CUFD.
     *
     * No se llama a registroPuntoVenta: el punto de venta ya esta del otro lado.
     * Llamarlo crearia OTRO con un codigo nuevo, y no se puede borrar.
     */
    public function configurar(Request $request, Sucursal $sucursal, GestorCodigos $gestor): RedirectResponse
    {
        $datos = $request->validate([
            'codigo_punto_venta' => ['required', 'integer', 'min:0'],
            'nombre' => ['required', 'string', 'max:255'],
            'tipo_punto_venta' => ['nullable', 'integer'],
        ]);

        $existente = $sucursal->puntosVenta()
            ->where('codigo_punto_venta', $datos['codigo_punto_venta'])
            ->first();

        if ($existente !== null) {
            return $this->volver($sucursal, "El punto de venta {$datos['codigo_punto_venta']} ya estaba configurado.");
        }

        $puntoVenta = $sucursal->puntosVenta()->create([
            'codigo_punto_venta' => $datos['codigo_punto_venta'],
            'nombre' => $datos['nombre'],
            'tipo_punto_venta' => $datos['tipo_punto_venta'] ?? 1,
            'siguiente_factura' => 1,
            'activo' => true,
            // El SIN ya lo tiene: por eso aparecio en su lista. Marcarlo es lo
            // que habilita emitir y lo que evita que el paso 10 lo registre
            // otra vez creando un duplicado del otro lado.
            'registrado_en_siat' => now(),
        ]);

        return $this->volver($sucursal, "Punto de venta {$puntoVenta->codigo_punto_venta} configurado. ".$this->pedirCodigos($gestor, $puntoVenta));
    }

    /**
     * Le pide CUIS y CUFD al SIN, best-effort.
     *
     * Si el SIAT no responde, el punto de venta igual queda configurado y el
     * operador reintenta con los botones de la ficha: una caida del SIN no
     * puede dejar el alta a medias.
     */
    private function pedirCodigos(GestorCodigos $gestor, PuntoVenta $puntoVenta): string
    {
        try {
            $gestor->solicitarCuis($puntoVenta);
        } catch (SiatException $e) {
            return "El CUIS no se pudo pedir: {$e->getMessage()}";
        }

        try {
            $gestor->solicitarCufd($puntoVenta);
        } catch (SiatException $e) {
            return "CUIS obtenido. El CUFD no se pudo pedir: {$e->getMessage()}";
        }

        return 'Ya tiene CUIS y CUFD vigentes.';
    }

    /**
     * Vuelve a la ficha del cliente con un aviso.
     */
    private function volver(Sucursal $sucursal, string $mensaje): RedirectResponse
    {
        return redirect()
            ->route('admin.empresas.show', $sucursal->empresa_id)
            ->with('estado', $mensaje);
    }

    /**
     * Adopta un codigo de punto de venta que ya existe en el SIAT.
     *
     * Sirve para reconciliar cuando el codigo local no coincide con el del SIN
     * (el local arranca en 0 y el SIN asigna el suyo), y para reutilizar uno ya
     * registrado en vez de crear otro duplicado.
     */
    public function adoptarCodigo(Request $request, PuntoVenta $puntoVenta): RedirectResponse
    {
        $datos = $request->validate([
            'codigo_punto_venta' => ['required', 'integer', 'min:0'],
        ]);

        $puntoVenta->update([
            'codigo_punto_venta' => $datos['codigo_punto_venta'],
            'registrado_en_siat' => now(),
        ]);

        return redirect()
            ->route('admin.empresas.show', $puntoVenta->sucursal->empresa_id)
            ->with('estado', "Punto de venta sincronizado con el codigo {$datos['codigo_punto_venta']} del SIAT.");
    }
}
