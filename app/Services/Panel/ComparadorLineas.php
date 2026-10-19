<?php

namespace App\Services\Panel;

/**
 * Diferencias linea a linea entre dos textos (por ejemplo, la factura de una
 * llamada rechazada contra la de la ultima aceptada).
 *
 * Es el algoritmo clasico de subsecuencia comun mas larga: con documentos de
 * unos cientos de lineas es instantaneo y no hace falta una dependencia.
 */
class ComparadorLineas
{
    /** Lineas iguales que se muestran alrededor de cada cambio. */
    private const CONTEXTO = 2;

    /**
     * Devuelve la comparacion ya recortada: los tramos largos sin cambios se
     * resumen en un solo renglon 'salto' con la cantidad de lineas omitidas.
     *
     * @param  list<string>  $antes
     * @param  list<string>  $despues
     * @return list<array{tipo: string, texto: string}> tipo: igual|quitada|agregada|salto
     */
    public function comparar(array $antes, array $despues): array
    {
        return $this->recortar($this->diferencias($antes, $despues));
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{tipo: string, texto: string}>
     */
    private function diferencias(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        // largo[i][j] = subsecuencia comun mas larga de a[i..] y b[j..].
        $largo = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $largo[$i][$j] = $a[$i] === $b[$j]
                    ? $largo[$i + 1][$j + 1] + 1
                    : max($largo[$i + 1][$j], $largo[$i][$j + 1]);
            }
        }

        $resultado = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $resultado[] = ['tipo' => 'igual', 'texto' => $a[$i]];
                $i++;
                $j++;
            } elseif ($largo[$i + 1][$j] >= $largo[$i][$j + 1]) {
                $resultado[] = ['tipo' => 'quitada', 'texto' => $a[$i++]];
            } else {
                $resultado[] = ['tipo' => 'agregada', 'texto' => $b[$j++]];
            }
        }

        while ($i < $n) {
            $resultado[] = ['tipo' => 'quitada', 'texto' => $a[$i++]];
        }

        while ($j < $m) {
            $resultado[] = ['tipo' => 'agregada', 'texto' => $b[$j++]];
        }

        return $resultado;
    }

    /**
     * @param  list<array{tipo: string, texto: string}>  $lineas
     * @return list<array{tipo: string, texto: string}>
     */
    private function recortar(array $lineas): array
    {
        $cambios = array_keys(array_filter($lineas, fn (array $l) => $l['tipo'] !== 'igual'));
        $visibles = [];

        foreach ($cambios as $indice) {
            for ($k = max(0, $indice - self::CONTEXTO); $k <= min(count($lineas) - 1, $indice + self::CONTEXTO); $k++) {
                $visibles[$k] = true;
            }
        }

        $resultado = [];
        $omitidas = 0;

        foreach ($lineas as $k => $linea) {
            if (isset($visibles[$k])) {
                if ($omitidas > 0) {
                    $resultado[] = ['tipo' => 'salto', 'texto' => "{$omitidas} lineas iguales"];
                    $omitidas = 0;
                }

                $resultado[] = $linea;
            } else {
                $omitidas++;
            }
        }

        if ($omitidas > 0) {
            $resultado[] = ['tipo' => 'salto', 'texto' => "{$omitidas} lineas iguales"];
        }

        return $resultado;
    }
}
