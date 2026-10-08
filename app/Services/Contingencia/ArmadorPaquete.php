<?php

namespace App\Services\Contingencia;

use App\Models\Factura;
use App\Models\Paquete;

/**
 * Arma el contenido de un paquete de contingencia: un archivo TAR con un XML
 * firmado por factura. ServicioFacturacion lo comprime en gzip y lo hashea al
 * enviarlo con recepcionPaqueteFactura.
 *
 * Antes armaba un XML propio (<paquete>...</paquete>) con las facturas
 * anidadas. El SIN no recibe eso: el paquete es un TAR.GZ de documentos
 * sueltos, el mismo formato que usan los sistemas que ya pasaron la etapa de
 * paquetes. Se arma a mano (formato ustar) para no depender de ext-phar.
 */
class ArmadorPaquete
{
    private const BLOQUE = 512;

    /**
     * TAR con las facturas asignadas a ese paquete, una por archivo.
     */
    public function armar(Paquete $paquete): string
    {
        // Se toman SOLO las facturas asignadas a este paquete. Filtrar por
        // punto de venta + estado incluiria facturas que entraron a
        // contingencia despues de armarlo.
        $archivos = Factura::where('paquete_id', $paquete->id)
            ->whereNotNull('xml_firmado')
            ->orderBy('numero_factura')
            ->get()
            ->mapWithKeys(fn (Factura $f): array => ["{$f->cuf}.xml" => (string) $f->xml_firmado])
            ->all();

        return $this->tar($archivos);
    }

    /**
     * Arma un TAR (ustar) en memoria.
     *
     * @param  array<string, string>  $archivos  nombre => contenido.
     */
    public function tar(array $archivos): string
    {
        $tar = '';

        foreach ($archivos as $nombre => $contenido) {
            $tar .= $this->cabecera((string) $nombre, strlen($contenido));
            $tar .= str_pad($contenido, (int) ceil(strlen($contenido) / self::BLOQUE) * self::BLOQUE, "\0");
        }

        // El fin del archivo son dos bloques en cero.
        return $tar.str_repeat("\0", self::BLOQUE * 2);
    }

    /**
     * Cabecera ustar de 512 bytes de un archivo regular.
     */
    private function cabecera(string $nombre, int $tamanio): string
    {
        $cabecera = str_pad(substr($nombre, 0, 99), 100, "\0")
            .sprintf('%07o', 0644)."\0"           // modo
            .sprintf('%07o', 0)."\0"              // uid
            .sprintf('%07o', 0)."\0"              // gid
            .sprintf('%011o', $tamanio)."\0"      // tamanio
            .sprintf('%011o', time())."\0"        // fecha
            .str_repeat(' ', 8)                    // checksum: espacios mientras se calcula
            .'0'                                   // archivo regular
            .str_repeat("\0", 100)                // enlace
            ."ustar\0".'00'
            .str_pad('', 32, "\0").str_pad('', 32, "\0")
            .str_repeat("\0", 16)                 // dispositivo
            .str_repeat("\0", 155);               // prefijo

        $cabecera = str_pad($cabecera, self::BLOQUE, "\0");
        $suma = array_sum(array_map('ord', str_split($cabecera)));

        return substr_replace($cabecera, sprintf('%06o', $suma)."\0 ", 148, 8);
    }
}
