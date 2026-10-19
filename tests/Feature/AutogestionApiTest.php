<?php

use App\Exceptions\SiatException;
use App\Models\Certificado;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Panel\PuntosVentaSiat;
use App\Services\Siat\FabricaServicios;
use App\Services\Siat\GestorCodigos;
use App\Services\Siat\ServicioOperaciones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/**
 * El .p12 de prueba de la factory, con su contrasena.
 *
 * @return array{0: string, 1: string}
 */
function p12Autogestion(): array
{
    $certificado = Certificado::factory()->firmable()->make();

    return [base64_decode($certificado->contenido_p12), $certificado->passphrase];
}

function tokenAutogestion(Empresa $empresa, string $perfil = 'administracion'): string
{
    return $empresa->emitirTokenApi('Test', $perfil)->plainTextToken;
}

// --- Certificado ---------------------------------------------------------------------

test('el cliente sube su .p12 y queda activo; nunca se devuelve el certificado', function () {
    $empresa = Empresa::factory()->create();
    $token = tokenAutogestion($empresa);

    $this->withToken($token)->getJson('/api/v1/certificado')->assertSuccessful()->assertJsonPath('certificado', null);

    [$p12, $clave] = p12Autogestion();

    $this->withToken($token)->post('/api/v1/certificado', [
        'archivo' => UploadedFile::fake()->createWithContent('firma.p12', $p12),
        'passphrase' => $clave,
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('certificado.vigente', true)
        ->assertJsonMissingPath('certificado.contenido_p12')
        ->assertJsonMissingPath('certificado.passphrase');

    expect($empresa->certificadoActivo)->not->toBeNull();

    $this->withToken($token)->getJson('/api/v1/certificado')->assertJsonPath('certificado.vigente', true);
});

test('un .p12 que no abre se rechaza y deja el anterior activo', function () {
    $empresa = Empresa::factory()->create();
    $anterior = $empresa->certificados()->create(['contenido_p12' => 'x', 'passphrase' => 'y', 'activo' => true]);

    $this->withToken(tokenAutogestion($empresa))->post('/api/v1/certificado', [
        'archivo' => UploadedFile::fake()->createWithContent('firma.p12', p12Autogestion()[0]),
        'passphrase' => 'equivocada',
    ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('archivo');

    expect($empresa->fresh()->certificadoActivo->id)->toBe($anterior->id);
});

test('una caja no carga certificados', function () {
    $empresa = Empresa::factory()->create();

    $this->withToken(tokenAutogestion($empresa, 'caja'))->post('/api/v1/certificado', [], ['Accept' => 'application/json'])
        ->assertForbidden();
});

// --- Registrar punto de venta en el SIN ------------------------------------------------

/**
 * SIN simulado para el registro: responde lo indicado, y los codigos se piden
 * sin errores.
 */
function sinRegistra(object $respuesta): void
{
    $operaciones = Mockery::mock(ServicioOperaciones::class);
    $llamada = $operaciones->shouldReceive('registrarPuntoVentaPara')->with(3, 'Caja 2', 2, 'CUIS-X');
    $respuesta instanceof SiatException ? $llamada->andThrow($respuesta) : $llamada->andReturn($respuesta);

    test()->mock(FabricaServicios::class, fn ($m) => $m->shouldReceive('operaciones')->andReturn($operaciones));
    test()->mock(PuntosVentaSiat::class, function ($m) {
        $m->shouldReceive('cuisParaConsultar')->andReturn('CUIS-X');
        $m->shouldReceive('olvidar');
    });
    test()->mock(GestorCodigos::class, function ($m) {
        $m->shouldReceive('solicitarCuis');
        $m->shouldReceive('solicitarCufd');
    });
}

test('registrar en el SIN crea el punto de venta con el codigo que asigno el SIN', function () {
    $empresa = Empresa::factory()->create();
    Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 3]);
    sinRegistra((object) ['RespuestaRegistroPuntoVenta' => (object) ['transaccion' => true, 'codigoPuntoVenta' => 5]]);

    $this->withToken(tokenAutogestion($empresa))
        ->postJson('/api/v1/puntos-venta/registrar-siat', ['sucursal' => 3, 'nombre' => 'Caja 2', 'tipo_punto_venta' => 2])
        ->assertCreated()
        ->assertJsonPath('punto_venta.punto_venta', 5)
        ->assertJsonPath('punto_venta.registrado_en_siat', true)
        ->assertJsonPath('mensaje', 'El SIN creo el punto de venta 5. Ya tiene CUIS y CUFD vigentes.');

    expect(PuntoVenta::sole()->registrado_en_siat)->not->toBeNull();
});

test('si el SIN rechaza o no devuelve codigo, no se crea nada local', function (object $respuesta, string $mensaje) {
    $empresa = Empresa::factory()->create();
    Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 3]);
    sinRegistra($respuesta);

    $this->withToken(tokenAutogestion($empresa))
        ->postJson('/api/v1/puntos-venta/registrar-siat', ['sucursal' => 3, 'nombre' => 'Caja 2', 'tipo_punto_venta' => 2])
        ->assertStatus(502)
        ->assertJsonPath('error', 'REGISTRO_SIAT_FALLIDO')
        ->assertJsonPath('mensaje', fn (string $m) => str_contains($m, $mensaje));

    expect(PuntoVenta::count())->toBe(0);
})->with([
    'rechazo' => [(object) ['RespuestaRegistroPuntoVenta' => (object) ['transaccion' => false, 'mensajesList' => (object) ['codigo' => 1, 'descripcion' => 'NOMBRE REPETIDO']]], 'El SIN rechazo el registro'],
    'sin codigo' => [(object) ['RespuestaRegistroPuntoVenta' => (object) ['transaccion' => true]], 'NO reintentes'],
    'caido' => [new SiatException('timeout'), 'No se pudo registrar: timeout'],
]);
