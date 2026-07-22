<?php

use App\Exceptions\SiatException;
use App\Models\Cufd;
use App\Models\Empresa;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Services\Siat\SiatClient;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- Modelos y relaciones -------------------------------------------------

test('la empresa cuelga sucursales y puntos de venta', function () {
    $empresa = Empresa::factory()->create();
    $sucursal = Sucursal::factory()->for($empresa)->create();
    $pv = PuntoVenta::factory()->for($sucursal)->create();

    expect($empresa->sucursales)->toHaveCount(1)
        ->and($sucursal->puntosVenta->first()->is($pv))->toBeTrue()
        ->and($pv->sucursal->empresa->is($empresa))->toBeTrue();
});

test('el token delegado se guarda cifrado y se lee en claro', function () {
    $empresa = Empresa::factory()->create(['token_delegado' => 'TOKEN-SECRETO-123']);

    // En la fila cruda no debe verse el token en texto plano.
    $crudo = DB::table('empresas')->where('id', $empresa->id)->value('token_delegado');
    expect($crudo)->not->toBe('TOKEN-SECRETO-123');

    // Pero el modelo lo descifra transparente.
    expect($empresa->fresh()->token_delegado)->toBe('TOKEN-SECRETO-123');
});

test('cufdVigente devuelve el vigente e ignora el vencido', function () {
    $pv = PuntoVenta::factory()->create();
    Cufd::factory()->for($pv)->vencido()->create();
    $vigente = Cufd::factory()->for($pv)->create();

    expect($pv->cufdVigente()?->is($vigente))->toBeTrue();
});

test('cufdVigente devuelve null cuando no hay ninguno vigente', function () {
    $pv = PuntoVenta::factory()->create();
    Cufd::factory()->for($pv)->vencido()->create();

    expect($pv->cufdVigente())->toBeNull();
});

// --- SiatClient -----------------------------------------------------------

test('urlWsdl arma la URL segun el ambiente de la empresa', function () {
    $piloto = Empresa::factory()->make(['codigo_ambiente' => 2]);
    $produccion = Empresa::factory()->make(['codigo_ambiente' => 1]);

    expect((new SiatClient($piloto))->urlWsdl('codigos'))
        ->toBe('https://pilotosiatservicios.impuestos.gob.bo/v2/FacturacionCodigos?wsdl');

    expect((new SiatClient($produccion))->urlWsdl('codigos'))
        ->toBe('https://siatrest.impuestos.gob.bo/v2/FacturacionCodigos?wsdl');
});

test('urlWsdl lanza SiatException con un servicio desconocido', function () {
    $empresa = Empresa::factory()->make();

    (new SiatClient($empresa))->urlWsdl('inexistente');
})->throws(SiatException::class);

// --- Comando siat:probar --------------------------------------------------

test('siat:probar falla si la empresa no existe', function () {
    $this->artisan('siat:probar', ['empresa' => 999])
        ->assertFailed();
});

test('siat:probar falla si la empresa no tiene token', function () {
    $empresa = Empresa::factory()->create(['token_delegado' => null]);

    $this->artisan('siat:probar', ['empresa' => $empresa->id])
        ->expectsOutputToContain('no tiene token delegado')
        ->assertFailed();
});
