<?php

namespace App\Services\Factura;

/**
 * Genera el CUF (Codigo Unico de Factura) de forma LOCAL, antes de enviar al
 * SIAT. Por eso una factura emitida sin internet ya es legalmente valida.
 *
 * Pasos (ver seccion 9.2 del documento maestro):
 *   1. Concatenar 9 campos numericos con ancho fijo -> 53 digitos.
 *   2. Calcular un digito verificador con modulo 11 y pegarlo al final.
 *   3. Convertir esos 54 digitos a base 16.
 *   4. Concatenar el codigo de control del CUFD.
 *
 * Los tres primeros pasos estan VERIFICADOS contra un sistema en produccion
 * facturando ante el SIN (proyecto 'ventas', modalidad computarizada): el
 * algoritmo del CUF es el mismo en ambas modalidades, lo que cambia es el
 * documento que despues se firma y se envia.
 */
class GeneradorCuf
{
    /**
     * Anchos fijos de cada campo, en el orden exacto de concatenacion.
     * La suma da 53 digitos; con el verificador, 54.
     *
     * VERIFICADO contra un sistema en produccion: 'tipo_factura' ocupa UN solo
     * digito (antes aca eran dos, y la cadena salia con un cero de mas, lo que
     * cambia el verificador y el hexadecimal, o sea todo el CUF).
     */
    private const ANCHOS = [
        'nit' => 13,
        'fecha' => 17,           // AAAAMMDDHHIISSNNN (con milisegundos)
        'sucursal' => 4,
        'modalidad' => 1,
        'tipo_emision' => 1,
        'tipo_factura' => 1,
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
     * VERIFICADO contra un sistema en produccion facturando ante el SIN.
     *
     * Recorre la cadena de derecha a izquierda multiplicando por pesos que
     * ciclan de 2 a 9, y el digito es el RESTO de dividir la suma entre 11
     * —no 11 menos el resto, que es la variante de otros paises y la que este
     * archivo usaba antes—. El resto 10 se escribe 1 y el 11 se escribe 0.
     */
    public function digitoVerificador(string $numero): int
    {
        $suma = 0;
        $peso = 2;

        for ($i = strlen($numero) - 1; $i >= 0; $i--) {
            $suma += ((int) $numero[$i]) * $peso;

            $peso = $peso === 9 ? 2 : $peso + 1;
        }

        $digito = $suma % 11;

        return match ($digito) {
            10 => 1,
            11 => 0,
            default => $digito,
        };
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
