<?php

use App\Models\Certificado;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Concurrencia del correlativo — SOLO CONTRA MySQL
|--------------------------------------------------------------------------
|
| El resto de la suite corre en sqlite :memory: para tardar segundos, pero ahi
| lockForUpdate es un NO-OP: la parte mas sensible del sistema (que dos cajas
| nunca tomen el mismo numero de factura) quedaba sin probar de verdad.
|
| Este archivo corre contra un MySQL real, en una base aparte, y lanza varios
| procesos PHP de verdad en paralelo. Si no hay MySQL alcanzable, se salta.
|
| COMO CORRERLO
| -------------
|   1. Levantar MySQL:            sudo systemctl start mysql
|   2. Correr solo este grupo:    php artisan test --group=mysql
|      (o el archivo:             php artisan test tests/Feature/CorrelativoConcurrenciaTest.php)
|
| La base de pruebas se crea sola y se llama siat_api_test. Para usar otra:
|   DB_TEST_MYSQL_DATABASE=otra_base php artisan test --group=mysql
|
| NO usa la base de desarrollo (siat_api): este test hace migrate:fresh y
| borraria todo.
|
*/

pest()->group('mysql');

/**
 * Nombre de la conexion que apunta a la base de pruebas MySQL.
 */
const CONEXION_CONCURRENCIA = 'mysql_concurrencia';

/**
 * Datos de conexion al MySQL local, tomados del .env.
 *
 * @return array<string, mixed>
 */
function credencialesMysql(): array
{
    return [
        'driver' => 'mysql',
        'host' => env('DB_HOST', '127.0.0.1'),
        'port' => env('DB_PORT', '3306'),
        'username' => env('DB_USERNAME', 'root'),
        'password' => env('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
    ];
}

function baseDePruebasMysql(): string
{
    return env('DB_TEST_MYSQL_DATABASE', 'siat_api_test');
}

/**
 * Deja la base de pruebas creada y migrada, y devuelve el id de un punto de
 * venta con el correlativo arrancando en 1.
 */
function puntoVentaEnMysql(): int
{
    $base = baseDePruebasMysql();

    // Primero una conexion sin base para poder crearla si no existe.
    Config::set('database.connections.mysql_servidor', credencialesMysql() + ['database' => null]);
    DB::connection('mysql_servidor')->statement(
        "CREATE DATABASE IF NOT EXISTS `{$base}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
    );

    Config::set('database.connections.'.CONEXION_CONCURRENCIA, credencialesMysql() + ['database' => $base]);
    DB::purge(CONEXION_CONCURRENCIA);

    Artisan::call('migrate:fresh', ['--database' => CONEXION_CONCURRENCIA, '--force' => true]);

    $conexion = DB::connection(CONEXION_CONCURRENCIA);

    $empresaId = $conexion->table('empresas')->insertGetId([
        'nombre_comercial' => 'Ferreteria Concurrente',
        'razon_social' => 'FERRETERIA CONCURRENTE SRL',
        'nit' => '1234567890',
        'codigo_ambiente' => 2,
        'codigo_modalidad' => 1,
        'estado' => 'PRODUCCION',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $sucursalId = $conexion->table('sucursales')->insertGetId([
        'empresa_id' => $empresaId,
        'codigo_sucursal' => 0,
        'nombre' => 'Casa Matriz',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $puntoVentaId = $conexion->table('puntos_venta')->insertGetId([
        'sucursal_id' => $sucursalId,
        'codigo_punto_venta' => 0,
        'nombre' => 'Caja Principal',
        'tipo_punto_venta' => 1,
        'siguiente_factura' => 1,
        'activo' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // La modalidad electronica no admite un documento sin firmar, asi que sin
    // certificado activo EmisorFactura corta antes de reservar numero. Se crea
    // por el modelo y no con un insert crudo porque el .p12 y su passphrase van
    // cifrados: los procesos hijos comparten el APP_KEY de este .env, asi que
    // los pueden descifrar.
    Certificado::on(CONEXION_CONCURRENCIA)->create(
        Certificado::factory()->firmable()->raw(['empresa_id' => $empresaId]),
    );

    // Sin CUFD vigente EmisorFactura corta antes de reservar numero.
    $conexion->table('cufd')->insert([
        'punto_venta_id' => $puntoVentaId,
        'codigo' => 'CUFD-CONCURRENCIA',
        'codigo_control' => 'A1B2C3',
        'fecha_vigencia' => now()->addDay(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $puntoVentaId;
}

/**
 * Lanza en paralelo N procesos PHP que emiten una factura sobre el mismo punto
 * de venta usando EmisorFactura, el mismo camino que la API.
 *
 * Son procesos de verdad (no hilos simulados): es la unica forma de que dos
 * transacciones se pisen realmente sobre la misma fila.
 *
 * @return list<int> el numero de factura que saco cada proceso
 */
function emitirEnParalelo(int $cajas): array
{
    $entorno = [
        'APP_ENV' => 'local',
        'DB_CONNECTION' => 'mysql',
        'DB_DATABASE' => baseDePruebasMysql(),
        // El envio al SIAT es asincrono y aca no interesa: se descarta la cola
        // para que ningun proceso intente hablar SOAP con el SIN.
        'QUEUE_CONNECTION' => 'null',
    ];

    $procesos = [];

    for ($i = 0; $i < $cajas; $i++) {
        $proceso = new Process(
            [PHP_BINARY, 'artisan', 'tinker', '--execute', codigoDeEmision($i)],
            base_path(),
            $entorno,
        );
        $proceso->start();
        $procesos[] = $proceso;
    }

    $numeros = [];

    foreach ($procesos as $proceso) {
        $proceso->wait();

        expect($proceso->isSuccessful())->toBeTrue($proceso->getErrorOutput());

        preg_match('/NUMERO:(\d+):FIN/', $proceso->getOutput(), $coincidencias);
        $numeros[] = (int) ($coincidencias[1] ?? -1);
    }

    return $numeros;
}

/**
 * Codigo que corre cada proceso: una emision completa con su propia referencia
 * externa, para que la idempotencia no las una en una sola factura.
 */
function codigoDeEmision(int $caja): string
{
    return <<<PHP
        \$empresa = App\Models\Empresa::first();

        \$factura = app(App\Services\Factura\EmisorFactura::class)->emitir(\$empresa, [
            'sucursal' => 0,
            'punto_venta' => 0,
            'referencia_externa' => 'VTA-CONCURRENTE-{$caja}',
            'comprador' => [
                'tipo_documento' => 1,
                'numero_documento' => '1023456',
                'razon_social' => 'JUAN PEREZ',
                'email' => 'juan@correo.com',
            ],
            'metodo_pago' => 1,
            'usuario' => 'caja-{$caja}',
            'items' => [[
                'codigo_producto_sin' => 99100,
                'codigo_interno' => 'TOR-14',
                'descripcion' => 'Tornillo autoperforante',
                'cantidad' => 1,
                'unidad_medida' => 57,
                'precio_unitario' => 10,
            ]],
        ]);

        echo "NUMERO:{\$factura->numero_factura}:FIN";
        PHP;
}

test('cuatro cajas concurrentes nunca emiten el mismo numero de factura', function () {
    $puntoVentaId = puntoVentaEnMysql();

    $numeros = emitirEnParalelo(4);

    // Ninguno fallo al parsear y ninguno se repitio.
    expect($numeros)->not->toContain(-1);
    expect(array_unique($numeros))->toHaveCount(4);

    // Y el correlativo no dejo huecos: 1, 2, 3, 4 en algun orden.
    sort($numeros);
    expect($numeros)->toBe([1, 2, 3, 4]);

    $conexion = DB::connection(CONEXION_CONCURRENCIA);

    expect((int) $conexion->table('puntos_venta')->where('id', $puntoVentaId)->value('siguiente_factura'))
        ->toBe(5);

    // Las cuatro facturas existen y cada una tiene su propio CUF.
    expect($conexion->table('facturas')->count())->toBe(4);
    expect($conexion->table('facturas')->distinct()->count('cuf'))->toBe(4);
})->skip(fn () => ! hayMysqlDisponible(), 'MySQL no esta disponible: levantalo con "sudo systemctl start mysql".');

/**
 * Comprueba que el MySQL del .env este arriba. Si no lo esta, el test se salta
 * en vez de romper la suite de quien no tenga el servicio levantado.
 */
function hayMysqlDisponible(): bool
{
    if (! extension_loaded('pdo_mysql')) {
        return false;
    }

    try {
        Config::set('database.connections.mysql_ping', credencialesMysql() + ['database' => null]);
        DB::connection('mysql_ping')->getPdo();

        return true;
    } catch (Throwable) {
        return false;
    }
}
