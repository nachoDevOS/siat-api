<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Listados del panel: si no se eligio un rango de fechas, se muestra el
     * MES ACTUAL (del dia 1 a hoy) en vez de toda la historia.
     *
     * Solo cuando los parametros no vienen en la URL: si el operador borra las
     * fechas y filtra, llegan vacios y se respeta (ve todo).
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    protected function conMesActualPorDefecto(Request $request, array $filtros): array
    {
        if ($request->has('desde') || $request->has('hasta')) {
            return $filtros;
        }

        return [
            ...$filtros,
            'desde' => now()->startOfMonth()->toDateString(),
            'hasta' => now()->toDateString(),
        ];
    }
}
