<?php

namespace App\Services\Factura;

/**
 * Genera el CUF (Codigo Unico de Factura) de forma LOCAL, antes de enviar al
 * SIAT. Por eso una factura emitida sin internet ya es legalmente valida.
 *
 * Pasos (ver seccion 9.2 del documento maestro):
 *   1. Concatenar 9 campos numericos con ancho fijo -> 54 digitos.
 *   2. Calcular un digito verificador con modulo 11.
 *   3. Convertir el numero (54 digitos + verificador) a base 16.
 *   4. Concatenar el codigo de control del CUFD.
 *
 * IMPORTANTE: el detalle exacto del modulo 11 (multiplicadores y manejo del
 * resto) debe confirmarse contra el manual tecnico vigente del SIN antes de
 * produccion. La estructura y el orden de campos siguen el documento oficial;
 * si el SIN ajusta el algoritmo se cambia SOLO el metodo digitoVerificador().
 */
class GeneradorCuf
{
    /**
     * Anchos fijos de cada campo, en el orden exacto de concatenacion.
     * La suma da 54 digitos.
     */
    private const ANCHOS = [
        'nit' => 13,
        'fecha' => 17,           // AAAAMMDDHHIISSNNN (con milisegundos)
        'sucursal' => 4,
        'modalidad' => 1,
        'tipo_emision' => 1,
        'tipo_factura' => 2,
        'tipo_documento_sector' => 2,
        'numero_factura' => 10,
        'punto_venta' => 4,
    ];

    /**
     * @param  array{
     *     nit: int|string,
     *     fecha: string,
     *     sucursal: int,
     *     modalidad: int,
     *     tipo_emision: int,
     *     tipo_factura: int,
     *     tipo_documento_sector: int,
     *     numero_factura: int,
     *     punto_venta: int
     * }  $datos
     * @param  string  $codigoControlCufd  el codigo_control del CUFD vigente.
     */
    public function generar(array $datos, string $codigoControlCufd): string
    {
        $concatenado = $this->concatenar($datos);

        $verificador = $this->digitoVerificador($concatenado);

        $numeroConVerificador = $concatenado.$verificador;

        $base16 = $this->aBase16($numeroConVerificador);

        // El CUF final pega el codigo de control del CUFD al hexadecimal.
        return $base16.$codigoControlCufd;
    }

    /**
     * Rellena cada campo con ceros a la izquierda hasta su ancho fijo y los une.
     *
     * @param  array<string, int|string>  $datos
     */
    private function concatenar(array $datos): string
    {
        $cadena = '';

        foreach (self::ANCHOS as $campo => $ancho) {
            $valor = (string) ($datos[$campo] ?? '');

            // Solo deben quedar digitos; la fecha ya viene sin separadores.
            $valor = preg_replace('/\D/', '', $valor);

            $cadena .= str_pad($valor, $ancho, '0', STR_PAD_LEFT);
        }

        return $cadena;
    }

    /**
     * Digito verificador por modulo 11.
     *
     * Recorre la cadena de derecha a izquierda multiplicando por pesos que
     * ciclan de 2 a 9. El digito es 11 menos el resto; si da 10 u 11 se usa 0,
     * que es la convencion mas comun en las implementaciones publicas del SIAT.
     *
     * OJO: confirmar pesos (2..9 vs 2..7) y manejo del 10/11 contra el manual
     * del SIN. Es el unico punto sensible del algoritmo.
     */
    public function digitoVerificador(string $numero): int
    {
        $suma = 0;
        $peso = 2;

        for ($i = strlen($numero) - 1; $i >= 0; $i--) {
            $suma += ((int) $numero[$i]) * $peso;

            $peso = $peso === 9 ? 2 : $peso + 1;
        }

        $digito = 11 - ($suma % 11);

        if ($digito >= 10) {
            return 0;
        }

        return $digito;
    }

    /**
     * Convierte una cadena decimal larga (mas grande que un entero de 64 bits)
     * a base 16. Se usa aritmetica de precision arbitraria (bcmath si esta, o
     * una division manual) para no perder digitos.
     */
    private function aBase16(string $decimal): string
    {
        // Con bcmath la conversion es directa y exacta.
        if (function_exists('bcmod')) {
            $hex = '';
            $n = ltrim($decimal, '0');

            if ($n === '') {
                return '0';
            }

            while (bccomp($n, '0') > 0) {
                $resto = (int) bcmod($n, '16');
                $hex = dechex($resto).$hex;
                $n = bcdiv($n, '16', 0);
            }

            return strtoupper($hex);
        }

        // Respaldo sin bcmath: division larga digito a digito.
        return strtoupper($this->divisionLargaABase16($decimal));
    }

    /**
     * Division larga manual por 16 para entornos sin bcmath.
     */
    private function divisionLargaABase16(string $decimal): string
    {
        $decimal = ltrim($decimal, '0');

        if ($decimal === '') {
            return '0';
        }

        $hex = '';

        while ($decimal !== '' && $decimal !== '0') {
            $resto = 0;
            $cociente = '';

            for ($i = 0; $i < strlen($decimal); $i++) {
                $actual = $resto * 10 + (int) $decimal[$i];
                $cociente .= intdiv($actual, 16);
                $resto = $actual % 16;
            }

            $hex = dechex($resto).$hex;
            $decimal = ltrim($cociente, '0');
        }

        return $hex;
    }
}
