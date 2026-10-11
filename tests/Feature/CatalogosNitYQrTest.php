<?php

use App\Exceptions\SiatException;
use App\Jobs\SincronizarCatalogosEmpresa;
use App\Models\Cuis;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\ProductoServicio;
use App\Models\PuntoVenta;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Catalogos\SincronizadorEmpresa;
use App\Services\Factura\GeneradorQr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Punto de venta de una empresa, con o sin CUIS vigente.
 */
function puntoVentaDe(Empresa $empresa, bool $conCuis = true, int $codigo = 0): PuntoVenta
{
    $sucursal = $empresa->sucursales()->first()
        ?? Sucursal::factory()->for($empresa)->create(['codigo_sucursal' => 0]);
    $pv = PuntoVenta::factory()->for($sucursal)->create(['codigo_punto_venta' => $codigo]);

    $conCuis
        ? Cuis::factory()->create(['punto_venta_id' => $pv->id])
        : Cuis::factory()->vencido()->create(['punto_venta_id' => $pv->id]);

    return $pv;
}

// --- P1: catalogos del NIT --------------------------------------------------

test('la empresa encuentra un punto de venta con CUIS vigente y solo de ella', function () {
    $empresa = Empresa::factory()->create();
    puntoVentaDe($empresa, conCuis: false, codigo: 0);
    $conCuis = puntoVentaDe($empresa, conCuis: true, codigo: 1);
    puntoVentaDe(Empresa::factory()->create(), conCuis: true);

    expect($empresa->puntoVentaConCuisVigente()?->id)->toBe($conCuis->id);
});

test('sin CUIS vigente no hay punto de venta para sincronizar', function () {
    $empresa = Empresa::factory()->create();
    puntoVentaDe($empresa, conCuis: false);

    expect($empresa->puntoVentaConCuisVigente())->toBeNull();
});

test('la tarea diaria despacha la sincronizacion de cada empresa con token', function () {
    Queue::fake();
    $conToken = Empresa::factory()->create();
    $sinToken = Empresa::factory()->create(['token_delegado' => null]);

    $this->artisan('schedule:test', ['--name' => 'sincronizar-catalogos-empresas'])->assertSuccessful();

    Queue::assertPushed(SincronizarCatalogosEmpresa::class, fn ($job) => $job->empresaId === $conToken->id);
    Queue::assertNotPushed(SincronizarCatalogosEmpresa::class, fn ($job) => $job->empresaId === $sinToken->id);
});

test('el job sincroniza con el punto de venta que tiene CUIS', function () {
    $empresa = Empresa::factory()->create();
    $pv = puntoVentaDe($empresa);

    $this->mock(SincronizadorEmpresa::class, fn ($mock) => $mock->shouldReceive('sincronizarTodo')
        ->once()
        ->withArgs(fn (PuntoVenta $recibido) => $recibido->id === $pv->id)
        ->andReturn(['actividades' => 1, 'productos' => 2, 'leyendas' => 3]));

    app()->call([new SincronizarCatalogosEmpresa($empresa->id), 'handle']);
});

test('el job no llama al SIN si la empresa todavia no tiene CUIS', function () {
    $empresa = Empresa::factory()->create();
    puntoVentaDe($empresa, conCuis: false);

    $this->mock(SincronizadorEmpresa::class, fn ($mock) => $mock->shouldNotReceive('sincronizarTodo'));

    app()->call([new SincronizarCatalogosEmpresa($empresa->id), 'handle']);
});

test('el boton del panel sincroniza y muestra el resumen', function () {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create();
    puntoVentaDe($empresa);

    $this->mock(SincronizadorEmpresa::class, fn ($mock) => $mock->shouldReceive('sincronizarTodo')
        ->once()
        ->andReturn(['actividades' => 2, 'productos' => 40, 'leyendas' => 9]));

    $this->followingRedirects()
        ->post(route('admin.empresas.catalogos', $empresa))
        ->assertOk()
        ->assertSee('Catalogos sincronizados: 2 actividades, 40 productos y 9 leyendas.');
});

test('el panel muestra el error si el SIN rechaza o no hay CUIS', function (bool $conCuis, string $mensaje) {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create();
    puntoVentaDe($empresa, conCuis: $conCuis);

    $this->mock(SincronizadorEmpresa::class, fn ($mock) => $mock->shouldReceive('sincronizarTodo')
        ->andThrow(new SiatException('PUNTO DE VENTA INEXISTENTE')));

    $this->followingRedirects()
        ->post(route('admin.empresas.catalogos', $empresa))
        ->assertOk()
        ->assertSee($mensaje)
        ->assertSee('aviso-error', false);
})->with([
    'rechazo del SIN' => [true, 'No se pudieron sincronizar los catalogos: PUNTO DE VENTA INEXISTENTE'],
    'sin CUIS' => [false, 'Ningun punto de venta tiene CUIS vigente'],
]);

test('la ficha muestra cuantos productos homologados tiene la empresa', function () {
    $this->actingAs(User::factory()->create());
    $empresa = Empresa::factory()->create();
    ProductoServicio::create(['empresa_id' => $empresa->id, 'codigo_actividad' => '620100', 'codigo_producto' => '83141', 'descripcion' => 'X']);

    $this->get(route('admin.empresas.show', $empresa))
        ->assertOk()
        ->assertSee('1 productos homologados')
        ->assertSee('Sincronizar ahora');
});

// --- P2: QR por ambiente -------------------------------------------------------

test('el QR apunta a la consulta del ambiente de la empresa con t=2', function (bool $produccion, string $dominio) {
    $empresa = $produccion
        ? Empresa::factory()->enProduccion()->create(['nit' => '7633685015'])
        : Empresa::factory()->create(['nit' => '7633685015']);
    $factura = Factura::factory()->for($empresa)->create(['cuf' => 'ABC123', 'numero_factura' => 15]);

    expect(app(GeneradorQr::class)->urlVerificacion($factura))
        ->toBe("https://{$dominio}/consulta/QR?nit=7633685015&cuf=ABC123&numero=15&t=2");
})->with([
    'piloto' => [false, 'pilotosiat.impuestos.gob.bo'],
    'produccion' => [true, 'siat.impuestos.gob.bo'],
]);
