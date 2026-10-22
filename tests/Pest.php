<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Errores de validar un XML SIN FIRMAR contra un XSD V3 oficial del SIN
 * (tests/Fixtures/xsd-v3, bajados del anexo tecnico de la Ley 1733). Vacio =
 * el XML pasa la misma validacion que hace el piloto.
 *
 * Al XSD se le quita la firma (<ds:Signature> y su import): el XML se arma sin
 * firmar y la firma la prueba FirmadorXml por su lado.
 *
 * Con $carpeta 'xsd-v2' valida contra los XSD V2 (siatinfo), los de las
 * etapas originales del piloto por /v2.
 *
 * @return list<string>
 */
function erroresContraXsdV3(string $xml, string $xsd, string $carpeta = 'xsd-v3'): array
{
    $esquema = preg_replace(
        ['/<xs:import[^>]*\/>/', '/<xs:element ref="ds:Signature"\/>/'],
        '',
        file_get_contents(base_path("tests/Fixtures/{$carpeta}/{$xsd}")),
    );

    $doc = new DOMDocument;
    $doc->loadXML($xml);

    $previo = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $doc->schemaValidateSource($esquema);
    $errores = array_map(fn (LibXMLError $e): string => trim($e->message), libxml_get_errors());
    libxml_clear_errors();
    libxml_use_internal_errors($previo);

    return $errores;
}
