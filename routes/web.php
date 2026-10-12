<?php

use App\Http\Controllers\Admin\AccesoApiController;
use App\Http\Controllers\Admin\ApiController;
use App\Http\Controllers\Admin\CatalogoEmpresaController;
use App\Http\Controllers\Admin\CertificadoController;
use App\Http\Controllers\Admin\CodigoController;
use App\Http\Controllers\Admin\ConfiguracionController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmpresaController;
use App\Http\Controllers\Admin\FacturaController;
use App\Http\Controllers\Admin\MonitorController;
use App\Http\Controllers\Admin\PruebaPilotoController;
use App\Http\Controllers\Admin\PuntoVentaController;
use App\Http\Controllers\Admin\SucursalController;
use App\Http\Controllers\Admin\TokenApiController;
use App\Http\Controllers\Admin\TrabajoController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DocumentacionApiController;
use App\Http\Controllers\FacturaPublicaController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('admin.dashboard'));

/*
|--------------------------------------------------------------------------
| Factura publica (enlace firmado): la abre el sistema de ventas para
| mostrarla o imprimirla, y se le comparte al comprador. Sin firma valida
| responde 404 (ver bootstrap/app.php).
|--------------------------------------------------------------------------
*/
Route::middleware('signed:relative')->group(function () {
    Route::get('factura/{cuf}', [FacturaPublicaController::class, 'show'])->name('factura.publica');
    Route::get('factura/{cuf}/pdf', [FacturaPublicaController::class, 'pdf'])->name('factura.publica.pdf');
    Route::get('factura/{cuf}/rollo', [FacturaPublicaController::class, 'rollo'])->name('factura.publica.rollo');
});

/*
|--------------------------------------------------------------------------
| Documentacion publica de la API para los sistemas que se integran.
|--------------------------------------------------------------------------
| Sin sesion a proposito: describe el contrato y nada privado.
*/
Route::get('docs/api', [DocumentacionApiController::class, 'index'])->name('docs.api');
Route::get('docs/api/openapi.yaml', [DocumentacionApiController::class, 'especificacion'])->name('docs.api.especificacion');
Route::get('docs/api/postman/coleccion.json', [DocumentacionApiController::class, 'coleccionPostman'])->name('docs.api.postman.coleccion');
Route::get('docs/api/postman/entorno.json', [DocumentacionApiController::class, 'entornoPostman'])->name('docs.api.postman.entorno');

/*
|--------------------------------------------------------------------------
| Autenticacion del panel.
|--------------------------------------------------------------------------
| El login solo es accesible para invitados; el logout, solo autenticado.
*/
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

/*
|--------------------------------------------------------------------------
| Panel de administracion /admin (fase 2, Blade).
|--------------------------------------------------------------------------
| Todo el grupo exige sesion iniciada (middleware 'auth'): un invitado es
| redirigido al login.
*/
Route::prefix('admin')->name('admin.')->middleware('auth')->group(function () {

    // Tablero principal y consola de administracion de la API REST.
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('api', [ApiController::class, 'index'])->name('api');
    // Configuracion del proveedor: solo lectura, para diagnosticar sin abrir el .env.
    Route::get('configuracion', [ConfiguracionController::class, 'index'])->name('configuracion');

    Route::resource('empresas', EmpresaController::class);

    // Historial de la cola: todos los trabajos, exitosos y fallidos.
    Route::get('trabajos', [TrabajoController::class, 'index'])->name('trabajos.index');
    Route::post('trabajos/reintentar-todos', [TrabajoController::class, 'reintentarTodos'])->name('trabajos.reintentar-todos');
    Route::post('trabajos/{trabajo}/reintentar', [TrabajoController::class, 'reintentar'])->name('trabajos.reintentar');
    Route::delete('trabajos/{trabajo}', [TrabajoController::class, 'descartar'])->name('trabajos.descartar');

    // Facturas de todos los clientes, para soporte.
    Route::get('facturas', [FacturaController::class, 'index'])->name('facturas.index');
    Route::get('facturas/{factura}', [FacturaController::class, 'show'])->name('facturas.show');
    Route::get('facturas/{factura}/pdf', [FacturaController::class, 'pdf'])->name('facturas.pdf');
    Route::get('facturas/{factura}/xml', [FacturaController::class, 'xml'])->name('facturas.xml');
    Route::post('facturas/{factura}/verificar', [FacturaController::class, 'verificar'])->name('facturas.verificar');
    Route::post('facturas/{factura}/reenviar', [FacturaController::class, 'reenviar'])->name('facturas.reenviar');
    Route::get('facturas/{factura}/estado', [FacturaController::class, 'estado'])->name('facturas.estado');
    Route::post('facturas/{factura}/anular', [FacturaController::class, 'anular'])->name('facturas.anular');
    Route::post('facturas/{factura}/revertir', [FacturaController::class, 'revertir'])->name('facturas.revertir');

    // Avance de etapa del cliente ante el SIN (lo decide el SIN, aca se refleja).
    Route::post('empresas/{empresa}/estado', [EmpresaController::class, 'cambiarEstado'])
        ->name('empresas.estado');

    // Certificado, sucursales y pruebas cuelgan de una empresa.
    Route::post('empresas/{empresa}/certificados', [CertificadoController::class, 'store'])
        ->name('empresas.certificados.store');
    // Autofirmado para correr el piloto sin el de una entidad certificadora.
    // Solo en ambiente de pruebas.
    Route::post('empresas/{empresa}/certificados/prueba', [CertificadoController::class, 'generarPrueba'])
        ->name('empresas.certificados.prueba');
    // Acceso API de la empresa: datos de conexion, tokens, webhook y llamadas.
    Route::get('empresas/{empresa}/api', [AccesoApiController::class, 'show'])
        ->name('empresas.api');
    Route::put('empresas/{empresa}/api/webhook', [AccesoApiController::class, 'actualizarWebhook'])
        ->name('empresas.api.webhook');
    Route::post('empresas/{empresa}/tokens', [TokenApiController::class, 'store'])
        ->name('empresas.tokens.store');
    Route::delete('empresas/{empresa}/tokens/{token}', [TokenApiController::class, 'destroy'])
        ->name('empresas.tokens.destroy');

    // Catalogos del NIT (actividades, productos homologados, leyendas).
    Route::post('empresas/{empresa}/catalogos', CatalogoEmpresaController::class)
        ->name('empresas.catalogos');
    Route::post('empresas/{empresa}/sucursales', [SucursalController::class, 'store'])
        ->name('empresas.sucursales.store');
    // Municipio, direccion y telefono van en cada factura: hay que poder
    // corregirlos. El codigo de la sucursal no se edita (ver el controlador).
    Route::put('sucursales/{sucursal}', [SucursalController::class, 'update'])
        ->name('sucursales.update');

    // Consulta al SIAT que puntos de venta existen YA del otro lado. Registrar
    // uno es irreversible, asi que conviene mirar antes de crear otro.
    Route::post('sucursales/{sucursal}/puntos-venta/consultar', [PuntoVentaController::class, 'consultar'])
        ->name('sucursales.puntos-venta.consultar');

    // Crea un punto de venta NUEVO en el SIAT. Irreversible: el SIN asigna el
    // codigo y despues no se puede borrar, solo cerrar.
    Route::post('sucursales/{sucursal}/puntos-venta/registrar', [PuntoVentaController::class, 'registrarEnSiat'])
        ->name('sucursales.puntos-venta.registrar');

    // Configura en local un punto de venta que ya existe en el SIAT, con su
    // codigo real, y le pide CUIS y CUFD de una.
    Route::post('sucursales/{sucursal}/puntos-venta/configurar', [PuntoVentaController::class, 'configurar'])
        ->name('sucursales.puntos-venta.configurar');

    // Adopta un codigo que ya existe en el SIAT en vez de registrar uno nuevo.
    Route::post('puntos-venta/{puntoVenta}/adoptar-codigo', [PuntoVentaController::class, 'adoptarCodigo'])
        ->name('puntos-venta.adoptar-codigo');

    // Historial completo de codigos de un punto de venta, en su propia pantalla:
    // el SIN emite un CUFD por dia, asi que la lista crece sin techo.
    Route::get('puntos-venta/{puntoVenta}/codigos', [CodigoController::class, 'historial'])
        ->name('puntos-venta.codigos');

    // Codigos CUIS / CUFD de un punto de venta: siempre se piden al SIAT.
    Route::post('puntos-venta/{puntoVenta}/cuis', [CodigoController::class, 'solicitarCuis'])->name('codigos.cuis');
    Route::post('puntos-venta/{puntoVenta}/cuis-nuevo', [CodigoController::class, 'forzarCuis'])->name('codigos.cuis-nuevo');
    Route::post('puntos-venta/{puntoVenta}/cufd', [CodigoController::class, 'solicitarCufd'])->name('codigos.cufd');

    // Panel de pruebas piloto (fase 3).
    Route::get('empresas/{empresa}/pruebas', [PruebaPilotoController::class, 'show'])->name('pruebas.show');
    // Borra el conteo local de una etapa (o de todas) para volver a empezar.
    Route::delete('empresas/{empresa}/pruebas/ejecuciones', [PruebaPilotoController::class, 'limpiar'])
        ->name('pruebas.limpiar');
    // Una etapa entera del portal del SIN (I = Obtencion de CUIS, ...). Va
    // antes de {caso} para que 'etapas' no se tome como id de caso.
    Route::post('empresas/{empresa}/pruebas/etapas/{etapa}', [PruebaPilotoController::class, 'ejecutarEtapa'])
        ->whereNumber('etapa')
        ->name('pruebas.etapa');
    // Destraba una prueba de CUIS: el SIN no emite otro mientras haya uno
    // vigente. Cierra las operaciones del sistema en ese punto de venta.
    Route::post('empresas/{empresa}/pruebas/{caso}/cerrar-operaciones', [PruebaPilotoController::class, 'cerrarOperaciones'])
        ->name('pruebas.cerrar-operaciones');
    // Encola lo que le falta a una prueba de etapa (ej: las 50 de un catalogo).
    Route::post('empresas/{empresa}/pruebas/{caso}/completar', [PruebaPilotoController::class, 'completarCaso'])
        ->name('pruebas.completar');
    // Un solo paso, para reintentar el que fallo sin repetir los anteriores.
    Route::post('empresas/{empresa}/pruebas/{caso}', [PruebaPilotoController::class, 'ejecutarCaso'])
        ->name('pruebas.caso');
    // Datos que exige la especificacion del SIN para los pasos que emiten.
    Route::post('empresas/{empresa}/pruebas/{caso}/payload', [PruebaPilotoController::class, 'guardarPayload'])
        ->name('pruebas.payload');

    Route::get('monitor', [MonitorController::class, 'index'])->name('monitor');
});
