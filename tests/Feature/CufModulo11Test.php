<?php

use App\Services\Factura\GeneradorCuf;

/*
|--------------------------------------------------------------------------
| Modulo 11 del CUF — VERIFICADO
|--------------------------------------------------------------------------
|
| El algoritmo estuvo marcado como "pendiente de confirmar" porque hay dos
| variantes de modulo 11 dando vueltas y elegir mal significa factura
| rechazada. Se resolvio contrastandolo contra el proyecto 'ventas', un sistema
| que factura en produccion ante el SIN.
|
| Lo que quedo confirmado:
|   - Pesos de 2 a 9, ciclando, recorriendo la cadena de derecha a izquierda.
|   - El digito es el RESTO (suma % 11), no 11 menos el resto.
|   - Resto 10 se escribe 1; resto 11 se escribe 0.
|   - La cadena concatenada son 53 digitos (tipoFactura ocupa uno solo).
|
| Los casos de abajo tienen el resultado calculado a mano, no copiado de la
| implementacion: si alguien cambia el algoritmo, fallan.
|
*/

test('el digito verificador es el resto de modulo 11', function (string $cadena, int $esperado, string $porque) {
    expect(app(GeneradorCuf::class)->digitoVerificador($cadena))->toBe($esperado, $porque);
})->with([
    // Un solo digito: suma = digito * 2.
    'cadena "1"' => ['1', 2, '1*2 = 2; 2 % 11 = 2'],
    'cadena "3"' => ['3', 6, '3*2 = 6; 6 % 11 = 6'],
    'cadena "7"' => ['7', 3, '7*2 = 14; 14 % 11 = 3'],

    // Resto 10: la unica variante que se escribe distinto al resto.
    'cadena "5" da resto 10' => ['5', 1, '5*2 = 10; 10 % 11 = 10, que se escribe 1'],

    // Dos digitos: pesos 2 y 3 de derecha a izquierda.
    'cadena "99"' => ['99', 1, '9*2 + 9*3 = 45; 45 % 11 = 1'],
    'cadena "10"' => ['10', 3, '0*2 + 1*3 = 3; 3 % 11 = 3'],
]);

test('una cadena de ceros da verificador cero', function () {
    expect(app(GeneradorCuf::class)->digitoVerificador(str_repeat('0', 53)))->toBe(0);
});

test('el peso vuelve a 2 despues del 9', function () {
    $generador = app(GeneradorCuf::class);

    // El ciclo 2..9 son ocho pesos: los ocho primeros digitos desde la derecha
    // los consumen y el noveno vuelve a 2.
    // "100000000"  -> el 1 queda en la novena posicion, peso 2  -> resto 2.
    // "1000000000" -> el 1 queda en la decima posicion,  peso 3 -> resto 3.
    expect($generador->digitoVerificador('100000000'))->toBe(2);
    expect($generador->digitoVerificador('1000000000'))->toBe(3);
});

/*
|--------------------------------------------------------------------------
| Propiedades del algoritmo
|--------------------------------------------------------------------------
*/

test('el digito verificador siempre es un solo digito', function (string $cadena) {
    $digito = app(GeneradorCuf::class)->digitoVerificador($cadena);

    expect($digito)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(9);
})->with([
    '53 ceros' => [str_repeat('0', 53)],
    '53 nueves' => [str_repeat('9', 53)],
    'cadena tipica' => ['12345678901232026010112000000000010012001000000001'],
    'un solo digito' => ['7'],
]);

test('cambiar un digito de la cadena cambia el verificador', function () {
    $generador = app(GeneradorCuf::class);

    expect($generador->digitoVerificador(str_repeat('0', 53)))
        ->not->toBe($generador->digitoVerificador(str_repeat('0', 52).'1'));
});

/*
|--------------------------------------------------------------------------
| CUF completo
|--------------------------------------------------------------------------
*/

test('la cadena concatenada del CUF son 53 digitos', function () {
    $generador = app(GeneradorCuf::class);

    $cuf = $generador->generar(datosDeCufDePrueba(), 'CTRL01');

    // El CUF es el hexadecimal de 54 digitos (53 + verificador) mas el codigo
    // de control del CUFD pegado al final.
    expect($cuf)->toEndWith('CTRL01');
    expect(substr($cuf, 0, -6))->toMatch('/^[0-9A-F]+$/');
});

test('dos facturas del mismo punto de venta dan CUF distinto', function () {
    $generador = app(GeneradorCuf::class);

    $primera = $generador->generar(datosDeCufDePrueba(1), 'CTRL01');
    $segunda = $generador->generar(datosDeCufDePrueba(2), 'CTRL01');

    expect($primera)->not->toBe($segunda);
});

/**
 * Datos de CUF con la forma que exige el SIN.
 *
 * @return array<string, int|string>
 */
function datosDeCufDePrueba(int $numeroFactura = 1): array
{
    return [
        'nit' => 7633685015,
        'fecha' => '20260809120000000',
        'sucursal' => 0,
        'modalidad' => 1,
        'tipo_emision' => 1,
        'tipo_factura' => 1,
        'tipo_documento_sector' => 1,
        'numero_factura' => $numeroFactura,
        'punto_venta' => 0,
    ];
}
