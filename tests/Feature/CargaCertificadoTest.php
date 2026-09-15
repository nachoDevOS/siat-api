<?php

use App\Models\Certificado;
use App\Models\Empresa;
use App\Models\User;
use Database\Factories\CertificadoFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| El certificado se comprueba antes de reemplazar al anterior
|--------------------------------------------------------------------------
|
| Antes se guardaba sin abrirlo: una passphrase mal tipeada desactivaba el
| certificado que si funcionaba, el panel confirmaba "cargado y activado" y la
| falla recien aparecia al firmar la primera factura, dentro de un job. El
| cliente dejaba de facturar sin que nadie se enterara.
|
*/

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function archivoP12(): UploadedFile
{
    $ruta = tempnam(sys_get_temp_dir(), 'p12');
    file_put_contents($ruta, CertificadoFactory::bytesP12Prueba());

    return new UploadedFile($ruta, 'certificado.p12', 'application/x-pkcs12', null, true);
}

test('un certificado valido se carga y queda activo', function () {
    $empresa = Empresa::factory()->create();

    $this->post(route('admin.empresas.certificados.store', $empresa), [
        'archivo' => archivoP12(),
        'passphrase' => CertificadoFactory::passphraseP12Prueba(),
    ])->assertRedirect();

    $certificado = $empresa->certificados()->first();

    expect($certificado)->not->toBeNull()
        ->and($certificado->activo)->toBeTrue();
});

test('la fecha de vencimiento sale del propio certificado', function () {
    $empresa = Empresa::factory()->create();

    // No se manda 'vence_el': antes quedaba null y el aviso de vencimiento a
    // 30 dias no disparaba nunca, porque filtra por 'vence_el' no nulo.
    $this->post(route('admin.empresas.certificados.store', $empresa), [
        'archivo' => archivoP12(),
        'passphrase' => CertificadoFactory::passphraseP12Prueba(),
    ])->assertRedirect();

    // El .p12 de prueba se firma a 365 dias.
    expect($empresa->certificados()->first()->vence_el)->not->toBeNull();
});

test('una passphrase incorrecta no guarda nada', function () {
    $empresa = Empresa::factory()->create();

    $this->post(route('admin.empresas.certificados.store', $empresa), [
        'archivo' => archivoP12(),
        'passphrase' => 'la-que-no-es',
    ])->assertSessionHasErrors('archivo');

    expect($empresa->certificados()->count())->toBe(0);
});

test('un archivo que no es un p12 no guarda nada', function () {
    $empresa = Empresa::factory()->create();

    $this->post(route('admin.empresas.certificados.store', $empresa), [
        'archivo' => UploadedFile::fake()->createWithContent('cualquiera.p12', 'esto no es un pkcs12'),
        'passphrase' => 'secreto',
    ])->assertSessionHasErrors('archivo');

    expect($empresa->certificados()->count())->toBe(0);
});

test('un certificado invalido no desactiva al que estaba funcionando', function () {
    // El dano real del defecto: no era guardar basura, era perder el bueno.
    $empresa = Empresa::factory()->create();
    $bueno = Certificado::factory()->for($empresa)->firmable()->create();

    $this->post(route('admin.empresas.certificados.store', $empresa), [
        'archivo' => archivoP12(),
        'passphrase' => 'la-que-no-es',
    ])->assertSessionHasErrors('archivo');

    expect($bueno->fresh()->activo)->toBeTrue()
        ->and($empresa->certificadoActivo()->first()->id)->toBe($bueno->id);
});

test('cargar uno valido si desactiva al anterior', function () {
    $empresa = Empresa::factory()->create();
    $anterior = Certificado::factory()->for($empresa)->firmable()->create();

    $this->post(route('admin.empresas.certificados.store', $empresa), [
        'archivo' => archivoP12(),
        'passphrase' => CertificadoFactory::passphraseP12Prueba(),
    ])->assertRedirect();

    expect($anterior->fresh()->activo)->toBeFalse()
        ->and($empresa->certificados()->where('activo', true)->count())->toBe(1);
});
