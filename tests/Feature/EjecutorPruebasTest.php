<?php

use App\Exceptions\SiatException;
use App\Jobs\AnularFacturaEnSiat;
use App\Jobs\EnviarPaqueteContingencia;
use App\Models\ActividadEconomica;
use App\Models\CasoPrueba;
use App\Models\Certificado;
use App\Models\Cufd;
use App\Models\Cuis;
use App\Models\EjecucionPrueba;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\FacturaAnulada;
use App\Models\Paquete;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Pruebas\EjecutorPruebas;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\ServicioCodigos;
use App\Services\Siat\ServicioOperaciones;
use App\Services\Siat\ServicioSincronizacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Sustituye la fabrica por un doble. El ejecutor construia el SiatClient a
 * mano, asi que la secuencia del piloto no se podia probar (deuda #7).
 */
function fabricaDePruebas(
    ?ServicioCodigos $codigos = null,
    ?ServicioSincronizacion $sincronizacion = null,
    ?ServicioOperaciones $operaciones = null,
): void {
    test()->mock(FabricaServicios::class, function ($mock) use ($codigos, $sincronizacion, $operaciones) {
        $mock->shouldReceive('codigos')->andReturn($codigos ?? Mockery::mock(ServicioCodigos::class));
        $mock->shouldReceive('sincronizacion')->andReturn($sincronizacion ?? Mockery::mock(ServicioSincronizacion::class));
        $mock->shouldReceive('operaciones')->andReturn($operaciones ?? Mockery::mock(ServicioOperaciones::class));
    });
}

/**
 * Empresa con sucursal y punto de venta, que es el minimo para casi todo paso.
 */
function empresaConPuntoVenta(): Empresa
{
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => 0]);
    // Los pasos que emiten pasan por EmisorFactura, que exige poder firmar.
    Certificado::factory()->for($empresa)->firmable()->create();

    return $empresa->fresh();
}

function puntoVentaDe(Empresa $empresa): PuntoVenta
{
    return PuntoVenta::whereHas('sucursal', fn ($q) => $q->where('empresa_id', $empresa->id))->firstOrFail();
}

/**
 * Venta minima valida para los pasos de emision.
 *
 * @return array<string, mixed>
 */
function ventaDelPiloto(array $extra = []): array
{
    return array_merge([
        'sucursal' => 0,
        'punto_venta' => 0,
        'comprador' => [
            'tipo_documento' => 1,
            'numero_documento' => '1023456',
            'razon_social' => 'JUAN PEREZ',
            'email' => 'juan@correo.com',
        ],
        'metodo_pago' => 1,
        'usuario' => 'caja-01',
        'items' => [[
            'codigo_producto_sin' => 99100,
            'codigo_interno' => 'TOR-14',
            'descripcion' => 'Tornillo autoperforante',
            'cantidad' => 1,
            'unidad_medida' => 57,
            'precio_unitario' => 10,
        ]],
    ], $extra);
}

// ---- Registro de ejecuciones ----------------------------------------------

test('un caso exitoso guarda su ejecucion con la respuesta del SIN', function () {
    $empresa = Empresa::factory()->create();
    $caso = CasoPrueba::factory()->create(['tipo' => 'verificarComunicacion', 'orden' => 1]);

    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldReceive('verificarComunicacion')->andReturn((object) ['transaccion' => true]);
    fabricaDePruebas($codigos);

    $ejecucion = app(EjecutorPruebas::class)->ejecutarCaso($empresa, $caso);

    expect($ejecucion->estado)->toBe(EjecucionPrueba::ESTADO_EXITOSO);
    expect($ejecucion->respuesta)->toBe(['transaccion' => true]);
    expect($ejecucion->empresa_id)->toBe($empresa->id);
});

test('un fallo del SIAT se registra como FALLIDO en vez de propagarse', function () {
    $empresa = Empresa::factory()->create();
    $caso = CasoPrueba::factory()->create(['tipo' => 'verificarComunicacion', 'orden' => 1]);

    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldReceive('verificarComunicacion')->andThrow(new SiatException('SIAT no disponible'));
    fabricaDePruebas($codigos);

    $ejecucion = app(EjecutorPruebas::class)->ejecutarCaso($empresa, $caso);

    expect($ejecucion->estado)->toBe(EjecucionPrueba::ESTADO_FALLIDO);
    expect($ejecucion->respuesta['error'])->toContain('SIAT no disponible');
});

test('la secuencia se detiene en el primer caso obligatorio que falla', function () {
    $empresa = Empresa::factory()->create();
    CasoPrueba::factory()->create(['tipo' => 'verificarComunicacion', 'orden' => 1, 'obligatorio' => true]);
    CasoPrueba::factory()->create(['tipo' => 'fechaHora', 'orden' => 2, 'nombre' => 'Sincronizar fecha', 'obligatorio' => true]);
    CasoPrueba::factory()->create(['tipo' => 'fechaHora', 'orden' => 3, 'obligatorio' => true]);

    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldReceive('verificarComunicacion')->andReturn((object) ['ok' => true]);

    $sincronizacion = Mockery::mock(ServicioSincronizacion::class);
    $sincronizacion->shouldReceive('fechaHora')->andThrow(new SiatException('token vencido'));

    fabricaDePruebas($codigos, $sincronizacion);

    $resultado = app(EjecutorPruebas::class)->ejecutarSecuencia($empresa, CasoPrueba::FASE_PILOTO);

    // Se corta en el 2: el 3 no llega a ejecutarse.
    expect($resultado['ejecutados'])->toBe(2);
    expect($resultado['fallo'])->toBe('Sincronizar fecha');
    expect(EjecucionPrueba::count())->toBe(2);
});

test('un tipo de caso desconocido se registra como fallido', function () {
    $empresa = Empresa::factory()->create();
    $caso = CasoPrueba::factory()->create(['tipo' => 'operacionInexistente', 'orden' => 11]);

    fabricaDePruebas();

    $ejecucion = app(EjecutorPruebas::class)->ejecutarCaso($empresa, $caso);

    expect($ejecucion->estado)->toBe(EjecucionPrueba::ESTADO_FALLIDO);
    expect($ejecucion->respuesta['error'])->toContain('aun no implementado');
});

// ---- Pasos 4 y 5: los codigos quedan guardados ------------------------------

test('el paso de CUIS guarda el codigo para que el siguiente lo pueda usar', function () {
    $empresa = empresaConPuntoVenta();
    $caso = CasoPrueba::factory()->create(['tipo' => 'cuis', 'orden' => 4]);

    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldReceive('solicitarCuis')->andReturn((object) [
        'RespuestaCuis' => (object) ['codigo' => 'CUIS-DEL-SIN'],
    ]);
    fabricaDePruebas($codigos);

    $ejecucion = app(EjecutorPruebas::class)->ejecutarCaso($empresa, $caso);

    expect($ejecucion->estado)->toBe(EjecucionPrueba::ESTADO_EXITOSO);
    expect(puntoVentaDe($empresa)->cuisVigente()->codigo)->toBe('CUIS-DEL-SIN');
});

test('el paso de CUFD guarda tambien el codigo de control', function () {
    $empresa = empresaConPuntoVenta();
    Cuis::factory()->for(puntoVentaDe($empresa))->create();
    $caso = CasoPrueba::factory()->create(['tipo' => 'cufd', 'orden' => 5]);

    $codigos = Mockery::mock(ServicioCodigos::class);
    $codigos->shouldReceive('solicitarCufd')->andReturn((object) [
        'RespuestaCufd' => (object) ['codigo' => 'CUFD-1', 'codigoControl' => 'CTRL-1', 'direccion' => 'Av. Siempre Viva'],
    ]);
    fabricaDePruebas($codigos);

    app(EjecutorPruebas::class)->ejecutarCaso($empresa, $caso);

    // El codigo_control es insumo del CUF: sin el, los pasos de emision fallan.
    expect(puntoVentaDe($empresa)->cufdVigente()->codigo_control)->toBe('CTRL-1');
});

test('un paso que necesita CUIS dice cual correr primero', function () {
    $empresa = empresaConPuntoVenta();
    $caso = CasoPrueba::factory()->create(['tipo' => 'cufd', 'orden' => 5]);

    fabricaDePruebas();

    $ejecucion = app(EjecutorPruebas::class)->ejecutarCaso($empresa, $caso);

    expect($ejecucion->respuesta['error'])->toContain('Solicitar CUIS');
});

// ---- Pasos 6 a 10 -----------------------------------------------------------

test('el paso de actividades economicas las guarda para la empresa', function () {
    $empresa = empresaConPuntoVenta();
    Cuis::factory()->for(puntoVentaDe($empresa))->create();
    $caso = CasoPrueba::factory()->create(['tipo' => 'listaActividades', 'orden' => 7]);

    $sincronizacion = Mockery::mock(ServicioSincronizacion::class);
    $sincronizacion->shouldReceive('listaActividades')->andReturn((object) [
        'RespuestaListaParametricas' => (object) [
            'listaActividades' => (object) ['codigoCaeb' => '620000', 'descripcion' => 'PROGRAMACION', 'tipoActividad' => 'S'],
        ],
    ]);
    fabricaDePruebas(null, $sincronizacion);

    $ejecucion = app(EjecutorPruebas::class)->ejecutarCaso($empresa, $caso);

    expect($ejecucion->estado)->toBe(EjecucionPrueba::ESTADO_EXITOSO);
    expect(ActividadEconomica::where('empresa_id', $empresa->id)->count())->toBe(1);
});

test('el paso de registro de punto de venta guarda el codigo que asigna el SIN', function () {
    $empresa = empresaConPuntoVenta();
    Cuis::factory()->for(puntoVentaDe($empresa))->create();
    $caso = CasoPrueba::factory()->create(['tipo' => 'registroPuntoVenta', 'orden' => 10]);

    // Forma real de la respuesta, verificada contra el WSDL del piloto.
    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('registrarPuntoVenta')
        ->once()
        ->withArgs(fn (PuntoVenta $pv, string $cuis) => $pv->is(puntoVentaDe($empresa)))
        ->andReturn((object) [
            'RespuestaRegistroPuntoVenta' => (object) ['codigoPuntoVenta' => 9, 'transaccion' => true],
        ]);
    fabricaDePruebas(null, null, $operaciones);

    expect(app(EjecutorPruebas::class)->ejecutarCaso($empresa, $caso)->estado)
        ->toBe(EjecucionPrueba::ESTADO_EXITOSO);

    // El codigo local arrancaba en 0 y el SIN asigna el suyo: sin adoptarlo se
    // emitiria con un punto de venta que el SIN no reconoce.
    $puntoVenta = puntoVentaDe($empresa)->fresh();

    expect($puntoVenta->codigo_punto_venta)->toBe(9)
        ->and($puntoVenta->estaRegistradoEnSiat())->toBeTrue();
});

test('reintentar el paso 10 NO registra un punto de venta nuevo en el SIN', function () {
    $empresa = empresaConPuntoVenta();
    Cuis::factory()->for(puntoVentaDe($empresa))->create();
    $caso = CasoPrueba::factory()->create(['tipo' => 'registroPuntoVenta', 'orden' => 10]);

    // El SIN no tiene "registrar si no existe": cada llamada crea otro punto de
    // venta con codigo nuevo, y cerrarlo es irreversible. Dos clics en
    // "Reintentar" dejaban dos duplicados en la cuenta del contribuyente.
    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('registrarPuntoVenta')
        ->once()
        ->andReturn((object) [
            'RespuestaRegistroPuntoVenta' => (object) ['codigoPuntoVenta' => 9, 'transaccion' => true],
        ]);
    fabricaDePruebas(null, null, $operaciones);

    $ejecutor = app(EjecutorPruebas::class);
    $ejecutor->ejecutarCaso($empresa, $caso);
    $segunda = $ejecutor->ejecutarCaso($empresa, $caso);

    // La segunda corrida sigue siendo EXITOSA (no es un error reintentar), pero
    // no vuelve a llamar al SIN: el ->once() de arriba lo garantiza.
    expect($segunda->estado)->toBe(EjecucionPrueba::ESTADO_EXITOSO)
        ->and($segunda->respuesta['ya_registrado'])->toBeTrue()
        ->and(puntoVentaDe($empresa)->fresh()->codigo_punto_venta)->toBe(9);
});

test('un rechazo del SIN en el registro no marca el punto de venta como registrado', function () {
    $empresa = empresaConPuntoVenta();
    Cuis::factory()->for(puntoVentaDe($empresa))->create();
    $caso = CasoPrueba::factory()->create(['tipo' => 'registroPuntoVenta', 'orden' => 10]);

    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $operaciones->shouldReceive('registrarPuntoVenta')->andReturn((object) [
        'RespuestaRegistroPuntoVenta' => (object) [
            'transaccion' => false,
            'mensajesList' => (object) ['codigo' => 1, 'descripcion' => 'TIPO DE PUNTO DE VENTA INVALIDO'],
        ],
    ]);
    fabricaDePruebas(null, null, $operaciones);

    $ejecucion = app(EjecutorPruebas::class)->ejecutarCaso($empresa, $caso);

    expect($ejecucion->estado)->toBe(EjecucionPrueba::ESTADO_FALLIDO)
        ->and($ejecucion->respuesta['error'])->toContain('TIPO DE PUNTO DE VENTA INVALIDO')
        ->and(puntoVentaDe($empresa)->fresh()->estaRegistradoEnSiat())->toBeFalse();
});

// ---- Pasos 11 a 13: emision -------------------------------------------------

test('el paso de emision usa la venta del payload_ejemplo del caso', function () {
    Queue::fake();

    $empresa = empresaConPuntoVenta();
    Cufd::factory()->for(puntoVentaDe($empresa))->create();

    $caso = CasoPrueba::factory()->create([
        'tipo' => 'recepcionFactura',
        'orden' => 11,
        'payload_ejemplo' => ventaDelPiloto(),
    ]);

    fabricaDePruebas();

    $ejecucion = app(EjecutorPruebas::class)->ejecutarCaso($empresa, $caso);

    expect($ejecucion->estado)->toBe(EjecucionPrueba::ESTADO_EXITOSO);
    expect($ejecucion->respuesta['cuf'])->not->toBeEmpty();
    expect(Factura::where('empresa_id', $empresa->id)->count())->toBe(1);
});

test('sin payload_ejemplo el paso de emision dice exactamente que falta', function () {
    $empresa = empresaConPuntoVenta();
    Cufd::factory()->for(puntoVentaDe($empresa))->create();

    $caso = CasoPrueba::factory()->create([
        'tipo' => 'recepcionFactura',
        'orden' => 11,
        'payload_ejemplo' => null,
    ]);

    fabricaDePruebas();

    $ejecucion = app(EjecutorPruebas::class)->ejecutarCaso($empresa, $caso);

    // No se inventa la venta: se pide la de la especificacion del SIN.
    expect($ejecucion->estado)->toBe(EjecucionPrueba::ESTADO_FALLIDO);
    expect($ejecucion->respuesta['error'])->toContain('payload_ejemplo');
});

test('reintentar el paso de emision emite una factura nueva, no la anterior', function () {
    Queue::fake();

    $empresa = empresaConPuntoVenta();
    Cufd::factory()->for(puntoVentaDe($empresa))->create();

    $caso = CasoPrueba::factory()->create([
        'tipo' => 'recepcionFactura',
        'orden' => 11,
        'payload_ejemplo' => ventaDelPiloto(),
    ]);

    fabricaDePruebas();

    $ejecutor = app(EjecutorPruebas::class);
    $primera = $ejecutor->ejecutarCaso($empresa, $caso);
    $segunda = $ejecutor->ejecutarCaso($empresa, $caso);

    // Con una referencia_externa fija, la idempotencia habria devuelto la misma.
    expect($segunda->respuesta['cuf'])->not->toBe($primera->respuesta['cuf']);
    expect(Factura::count())->toBe(2);
});

// ---- Pasos 14 a 16 ----------------------------------------------------------

test('el paso de anulacion anula la ultima factura y encola su transmision', function () {
    Queue::fake();

    $empresa = empresaConPuntoVenta();
    $factura = Factura::factory()->create([
        'empresa_id' => $empresa->id,
        'punto_venta_id' => puntoVentaDe($empresa)->id,
        'estado' => Factura::ESTADO_VALIDADA,
    ]);

    $caso = CasoPrueba::factory()->create([
        'tipo' => 'anulacionFactura',
        'orden' => 14,
        'payload_ejemplo' => ['motivo' => 1],
    ]);

    fabricaDePruebas();

    $ejecucion = app(EjecutorPruebas::class)->ejecutarCaso($empresa, $caso);

    expect($ejecucion->estado)->toBe(EjecucionPrueba::ESTADO_EXITOSO);
    expect($factura->fresh()->estado)->toBe(Factura::ESTADO_ANULADA);
    expect(FacturaAnulada::where('factura_id', $factura->id)->first()->motivo)->toBe(1);

    Queue::assertPushed(AnularFacturaEnSiat::class);
});

test('sin motivo en el payload la anulacion no se inventa', function () {
    $empresa = empresaConPuntoVenta();
    Factura::factory()->create([
        'empresa_id' => $empresa->id,
        'punto_venta_id' => puntoVentaDe($empresa)->id,
    ]);

    $caso = CasoPrueba::factory()->create(['tipo' => 'anulacionFactura', 'orden' => 14, 'payload_ejemplo' => []]);

    fabricaDePruebas();

    $ejecucion = app(EjecutorPruebas::class)->ejecutarCaso($empresa, $caso);

    expect($ejecucion->estado)->toBe(EjecucionPrueba::ESTADO_FALLIDO);
    expect($ejecucion->respuesta['error'])->toContain('motivo');
});

test('el paso de contingencia abre el evento, arma el paquete y lo encola', function () {
    Queue::fake();

    $empresa = empresaConPuntoVenta();
    $factura = Factura::factory()->create([
        'empresa_id' => $empresa->id,
        'punto_venta_id' => puntoVentaDe($empresa)->id,
        'estado' => Factura::ESTADO_PENDIENTE,
    ]);

    $caso = CasoPrueba::factory()->create(['tipo' => 'recepcionPaquete', 'orden' => 16]);

    fabricaDePruebas();

    $ejecucion = app(EjecutorPruebas::class)->ejecutarCaso($empresa, $caso);

    expect($ejecucion->estado)->toBe(EjecucionPrueba::ESTADO_EXITOSO);
    expect(Paquete::count())->toBe(1);
    expect($factura->fresh()->paquete_id)->not->toBeNull();

    Queue::assertPushed(EnviarPaqueteContingencia::class);
});

// ---- Paso 17 ----------------------------------------------------------------

test('el ultimo paso falla listando los pasos que faltan', function () {
    $empresa = Empresa::factory()->create();
    CasoPrueba::factory()->create(['orden' => 1, 'nombre' => 'Verificar comunicacion']);
    $cierre = CasoPrueba::factory()->create(['tipo' => 'marcarAprobado', 'orden' => 17]);

    fabricaDePruebas();

    $ejecucion = app(EjecutorPruebas::class)->ejecutarCaso($empresa, $cierre);

    expect($ejecucion->estado)->toBe(EjecucionPrueba::ESTADO_FALLIDO);
    expect($ejecucion->respuesta['error'])->toContain('Verificar comunicacion');
});

test('el ultimo paso pasa cuando todos los anteriores estan en EXITOSO, sin cambiar el estado', function () {
    $empresa = Empresa::factory()->create(['estado' => Empresa::ESTADO_EN_PRUEBAS]);
    $previo = CasoPrueba::factory()->create(['orden' => 1]);
    $cierre = CasoPrueba::factory()->create(['tipo' => 'marcarAprobado', 'orden' => 17]);

    EjecucionPrueba::create([
        'empresa_id' => $empresa->id,
        'caso_id' => $previo->id,
        'estado' => EjecucionPrueba::ESTADO_EXITOSO,
        'respuesta' => [],
        'ejecutado_en' => now(),
    ]);

    fabricaDePruebas();

    $ejecucion = app(EjecutorPruebas::class)->ejecutarCaso($empresa, $cierre);

    expect($ejecucion->estado)->toBe(EjecucionPrueba::ESTADO_EXITOSO);
    expect($ejecucion->respuesta['listo_para_aprobar'])->toBeTrue();

    // Quien aprueba el piloto es el SIN: el ejecutor no toca el estado.
    expect($empresa->fresh()->estado)->toBe(Empresa::ESTADO_EN_PRUEBAS);
});
