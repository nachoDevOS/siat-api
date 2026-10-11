<?php

namespace App\Services\Factura;

/**
 * Escribe un monto en letras para la representacion grafica de la factura:
 * 1234.50 -> "UN MIL DOSCIENTOS TREINTA Y CUATRO 50/100 BOLIVIANOS".
 *
 * Logica pura y sin dependencias: se escribe a mano porque la extension intl
 * (NumberFormatter SPELLOUT) no esta garantizada en el servidor y su salida
 * no sigue las convenciones de las facturas bolivianas ("UN MIL", centavos
 * en "nn/100").
 */
class MontoEnLetras
{
    private const UNIDADES = ['', 'UNO', 'DOS', 'TRES', 'CUATRO', 'CINCO', 'SEIS', 'SIETE', 'OCHO', 'NUEVE',
        'DIEZ', 'ONCE', 'DOCE', 'TRECE', 'CATORCE', 'QUINCE', 'DIECISEIS', 'DIECISIETE', 'DIECIOCHO', 'DIECINUEVE',
        'VEINTE', 'VEINTIUNO', 'VEINTIDOS', 'VEINTITRES', 'VEINTICUATRO', 'VEINTICINCO', 'VEINTISEIS', 'VEINTISIETE',
        'VEINTIOCHO', 'VEINTINUEVE'];

    private const DECENAS = ['', '', '', 'TREINTA', 'CUARENTA', 'CINCUENTA', 'SESENTA', 'SETENTA', 'OCHENTA', 'NOVENTA'];

    private const CENTENAS = ['', 'CIENTO', 'DOSCIENTOS', 'TRESCIENTOS', 'CUATROCIENTOS', 'QUINIENTOS',
        'SEISCIENTOS', 'SETECIENTOS', 'OCHOCIENTOS', 'NOVECIENTOS'];

    public function bolivianos(float|string $monto): string
    {
        // Se trabaja en centavos enteros para no arrastrar errores de float.
        $centavos = (int) round(((float) $monto) * 100);
        $entero = intdiv($centavos, 100);

        return sprintf('%s %02d/100 BOLIVIANOS', $this->entero($entero), $centavos % 100);
    }

    /**
     * Parte entera en letras. Cero se escribe CERO.
     */
    public function entero(int $numero): string
    {
        if ($numero === 0) {
            return 'CERO';
        }

        $millones = intdiv($numero, 1_000_000);
        $miles = intdiv($numero % 1_000_000, 1000);
        $resto = $numero % 1000;

        $partes = [];

        if ($millones > 0) {
            $partes[] = $millones === 1 ? 'UN MILLON' : $this->hastaMil($millones, true).' MILLONES';
        }

        // En las facturas se escribe "UN MIL", no "MIL" solo.
        if ($miles > 0) {
            $partes[] = $this->hastaMil($miles, true).' MIL';
        }

        if ($resto > 0) {
            $partes[] = $this->hastaMil($resto, false);
        }

        return implode(' ', $partes);
    }

    /**
     * Del 1 al 999. Con $apocope, el "uno" final se acorta ("VEINTIUN MIL",
     * "TREINTA Y UN MILLONES") porque va delante de MIL o MILLONES.
     */
    private function hastaMil(int $numero, bool $apocope): string
    {
        if ($numero === 100) {
            return 'CIEN';
        }

        $centena = intdiv($numero, 100);
        $decena = $numero % 100;
        $partes = [];

        if ($centena > 0) {
            $partes[] = self::CENTENAS[$centena];
        }

        if ($decena > 0) {
            $partes[] = $decena < 30
                ? self::UNIDADES[$decena]
                : trim(self::DECENAS[intdiv($decena, 10)].($decena % 10 > 0 ? ' Y '.self::UNIDADES[$decena % 10] : ''));
        }

        $texto = implode(' ', $partes);

        return $apocope ? preg_replace('/UNO$/', 'UN', $texto) : $texto;
    }
}
