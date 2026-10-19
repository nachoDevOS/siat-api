<?php

use App\Models\Sitio\SolicitudContacto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    // El build de Vite no existe en las pruebas.
    $this->withoutVite();
});

/**
 * Los datos que la portada le pasa a la app React.
 *
 * @return array<string, mixed>
 */
function datosDeLaPortada(TestResponse $respuesta): array
{
    preg_match('#<script id="sitio-datos" type="application/json">(.*?)</script>#s', $respuesta->getContent(), $m);

    return json_decode($m[1], true);
}

/**
 * @return array<string, mixed>
 */
function solicitudValida(array $extra = []): array
{
    return array_merge([
        'nombre' => 'Mariela Quispe',
        'empresa' => 'Ferreteria El Sol',
        'correo' => 'mariela@ferreteriaelsol.bo',
        'interes' => 'integrar',
        'mensaje' => 'Tenemos un POS propio y queremos facturar en linea.',
    ], $extra);
}

test('la portada es el sitio publico, sin token, con la marca y la app React', function () {
    config(['sitio.marca' => 'SolucionDigital']);

    $respuesta = $this->get('/')->assertSuccessful()
        ->assertSee('<div id="sitio"></div>', false)
        ->assertSee('SolucionDigital');

    $datos = datosDeLaPortada($respuesta);

    expect($datos['marca'])->toBe('SolucionDigital')
        ->and($datos['rutas']['contacto'])->toBe(route('sitio.contacto'))
        ->and($datos['intereses'])->toHaveKey('integrar');
});

test('el sitio no publica precios ni planes', function () {
    $html = mb_strtolower($this->get('/')->getContent().implode('', array_map('file_get_contents', glob(resource_path('js/sitio/{,componentes/}*.jsx'), GLOB_BRACE))));

    // "precio_unitario" del ejemplo de codigo es un campo de la API, no un precio.
    expect($html)->not->toMatch('/\bprecios?\b(?!_)|\bplan(es)?\b|membres|suscrip|tarifa|cotiz|\bbs\.? ?\d|por mes|mensual/u');
});

test('WhatsApp y correo solo aparecen si estan configurados', function () {
    config(['sitio.whatsapp' => null, 'sitio.correo' => null]);
    $sin = datosDeLaPortada($this->get('/'));

    config(['sitio.whatsapp' => '+591 700-12345', 'sitio.correo' => 'ventas@solucion.bo']);
    $con = datosDeLaPortada($this->get('/'));

    expect($sin['whatsapp'])->toBeNull()->and($sin['correo'])->toBeNull()
        ->and($con['whatsapp'])->toBe('59170012345')
        ->and($con['correo'])->toBe('ventas@solucion.bo');
});

test('el formulario de contacto guarda la solicitud para el panel', function () {
    $this->postJson('/contacto', solicitudValida())
        ->assertCreated()
        ->assertJsonPath('exito', true);

    $solicitud = SolicitudContacto::sole();
    expect($solicitud->nombre)->toBe('Mariela Quispe')
        ->and($solicitud->ip)->toBe('127.0.0.1')
        ->and($solicitud->estaAtendida())->toBeFalse();
});

test('el formulario valida campos, frena bots y limita envios por IP', function () {
    // Cuenta todo envio, tambien los rechazados: 2 invalidos + 2 validos.
    config(['sitio.limite_contacto' => 4]);

    $this->postJson('/contacto', solicitudValida(['correo' => 'no-es-correo', 'mensaje' => 'hola', 'interes' => 'x']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['correo', 'mensaje', 'interes']);

    $this->postJson('/contacto', solicitudValida(['sitio_web' => 'https://spam.example']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sitio_web');

    $this->postJson('/contacto', solicitudValida())->assertCreated();
    $this->postJson('/contacto', solicitudValida())->assertCreated();
    $this->postJson('/contacto', solicitudValida())->assertTooManyRequests();

    expect(SolicitudContacto::count())->toBe(2);
});

test('el panel muestra las solicitudes pendientes y las marca atendidas', function () {
    $pendiente = SolicitudContacto::factory()->create(['nombre' => 'Rodrigo Mamani']);
    SolicitudContacto::factory()->atendida()->create(['nombre' => 'Lucia Vargas']);

    $this->get(route('admin.solicitudes.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->create());

    $this->get(route('admin.solicitudes.index'))->assertSuccessful()
        ->assertSee('Rodrigo Mamani')->assertDontSee('Lucia Vargas');
    $this->get(route('admin.solicitudes.index', ['ver' => 'todas']))->assertSee('Lucia Vargas');

    $this->post(route('admin.solicitudes.atender', $pendiente))->assertRedirect();

    expect($pendiente->fresh()->estaAtendida())->toBeTrue();
});

test('las vistas publicas no exponen datos internos ni la direccion del panel', function () {
    config(['siat.proveedor.nit' => '7633685015', 'siat.proveedor.codigo_sistema' => '22848EC6F66C9401C16F7']);
    User::factory()->create(['email' => 'admin@admin.com']);

    $contenido = $this->get('/')->getContent().$this->get('/docs/api')->getContent();

    expect($contenido)
        ->not->toContain('/admin')
        ->not->toContain('admin@admin.com')
        ->not->toContain('7633685015')
        ->not->toContain('22848EC6F66C9401C16F7')
        ->not->toContain(config('app.key'));
});
