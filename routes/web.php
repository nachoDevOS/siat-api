<?php

use App\Http\Controllers\Admin\ApiController;
use App\Http\Controllers\Admin\CertificadoController;
use App\Http\Controllers\Admin\CodigoController;
use App\Http\Controllers\Admin\ConfiguracionController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmpresaController;
use App\Http\Controllers\Admin\MonitorController;
use App\Http\Controllers\Admin\PruebaPilotoController;
use App\Http\Controllers\Admin\PuntoVentaController;
use App\Http\Controllers\Admin\SucursalController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('admin.dashboard'));

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

    // Avance de etapa del cliente ante el SIN (lo decide el SIN, aca se refleja).
    Route::post('empresas/{empresa}/estado', [EmpresaController::class, 'cambiarEstado'])
        ->name('empresas.estado');

    // Certificado, sucursales y pruebas cuelgan de una empresa.
    Route::post('empresas/{empresa}/certificados', [CertificadoController::class, 'store'])
        ->name('empresas.certificados.store');
    Route::post('empresas/{empresa}/sucursales', [SucursalController::class, 'store'])
        ->name('empresas.sucursales.store');

    // Puntos de venta cuelgan de una sucursal.
    Route::post('sucursales/{sucursal}/puntos-venta', [PuntoVentaController::class, 'store'])
        ->name('sucursales.puntos-venta.store');

    // Consulta al SIAT que puntos de venta existen YA del otro lado. Registrar
    // uno es irreversible, asi que conviene mirar antes de crear otro.
    Route::post('sucursales/{sucursal}/puntos-venta/consultar', [PuntoVentaController::class, 'consultar'])
        ->name('sucursales.puntos-venta.consultar');

    // Adopta un codigo que ya existe en el SIAT en vez de registrar uno nuevo.
    Route::post('puntos-venta/{puntoVenta}/adoptar-codigo', [PuntoVentaController::class, 'adoptarCodigo'])
        ->name('puntos-venta.adoptar-codigo');

    // Codigos CUIS / CUFD / CAFC de un punto de venta: solicitud al SIAT o carga manual.
    Route::post('puntos-venta/{puntoVenta}/cuis', [CodigoController::class, 'solicitarCuis'])->name('codigos.cuis');
    Route::post('puntos-venta/{puntoVenta}/cufd', [CodigoController::class, 'solicitarCufd'])->name('codigos.cufd');
    Route::post('puntos-venta/{puntoVenta}/cafc', [CodigoController::class, 'solicitarCafc'])->name('codigos.cafc');
    Route::post('puntos-venta/{puntoVenta}/cuis-manual', [CodigoController::class, 'cuisManual'])->name('codigos.cuis.manual');
    Route::post('puntos-venta/{puntoVenta}/cufd-manual', [CodigoController::class, 'cufdManual'])->name('codigos.cufd.manual');
    Route::post('puntos-venta/{puntoVenta}/cafc-manual', [CodigoController::class, 'cafcManual'])->name('codigos.cafc.manual');

    // Panel de pruebas piloto (fase 3).
    Route::get('empresas/{empresa}/pruebas', [PruebaPilotoController::class, 'show'])->name('pruebas.show');
    Route::post('empresas/{empresa}/pruebas', [PruebaPilotoController::class, 'ejecutar'])->name('pruebas.ejecutar');
    // Un solo paso, para reintentar el que fallo sin repetir los anteriores.
    Route::post('empresas/{empresa}/pruebas/{caso}', [PruebaPilotoController::class, 'ejecutarCaso'])
        ->name('pruebas.caso');
    // Datos que exige la especificacion del SIN para los pasos que emiten.
    Route::post('empresas/{empresa}/pruebas/{caso}/payload', [PruebaPilotoController::class, 'guardarPayload'])
        ->name('pruebas.payload');

    Route::get('monitor', [MonitorController::class, 'index'])->name('monitor');
});
