<?php

use Illuminate\Support\Facades\Route;

// SPA: no hay named route 'login', devolver 401
Route::get('/login', fn() => response()->json(['message' => 'Unauthenticated.'], 401))->name('login');
use App\Http\Controllers\Api\V1\Public\FechaPublicController;
use App\Http\Controllers\Api\V1\Public\CampeonatoPublicController;
use App\Http\Controllers\Api\V1\Public\PatrocinadorPublicController;
use App\Http\Controllers\Api\V1\CronometroController;
use App\Http\Controllers\Api\V1\Admin\AuthController;
use App\Http\Controllers\Api\V1\Admin\PatrocinadorController;
use App\Http\Controllers\Api\V1\Admin\CategoriaController;
use App\Http\Controllers\Api\V1\Admin\CampeonatoController;
use App\Http\Controllers\Api\V1\Admin\FechaController;
use App\Http\Controllers\Api\V1\Admin\TripulacionController;
use App\Http\Controllers\Api\V1\Admin\VueltaController;
use App\Http\Controllers\Api\V1\SyncController;

// Health
Route::get('/v1/health', [FechaPublicController::class, 'health']);

// Cronometro heartbeat (auth por Bearer api_token del dispositivo)
Route::post('/v1/cronometro/heartbeat', [CronometroController::class, 'heartbeat'])
    ->middleware('auth.dispositivo');
Route::get('/v1/cronometro/comandos-pendientes', [CronometroController::class, 'comandosPendientes'])
    ->middleware('auth.dispositivo');
Route::post('/v1/cronometro/comandos/{id}/confirmar', [CronometroController::class, 'confirmarComando'])
    ->middleware('auth.dispositivo');
Route::post('/v1/cronometro/eventos', [CronometroController::class, 'registrarEvento'])
    ->middleware('auth.dispositivo');
Route::post('/v1/cronometro/log', [CronometroController::class, 'registrarLog'])
    ->middleware('auth.dispositivo');

// Public
Route::prefix('v1/public')->group(function () {
    Route::get('/categorias', [FechaPublicController::class, 'catalogoCategorias']);
    Route::get('/fechas', [FechaPublicController::class, 'fechasFinalizadas']);
    Route::get('/fechas/activa', [FechaPublicController::class, 'activa']);
    Route::get('/fechas/{fechaId}/top3', [FechaPublicController::class, 'top3General']);
    Route::get('/fechas/{fechaId}/categorias', [FechaPublicController::class, 'categorias']);
    Route::get('/fechas/{fechaId}/categorias/{categoriaId}/ranking', [FechaPublicController::class, 'ranking']);
    Route::get('/fechas/{fechaId}/tripulaciones/{tripId}', [FechaPublicController::class, 'tripulacionDetalle']);

    // Campeonatos
    Route::get('/campeonatos', [CampeonatoPublicController::class, 'index']);
    Route::get('/campeonatos/activo', [CampeonatoPublicController::class, 'activo']);
    Route::get('/campeonatos/{id}', [CampeonatoPublicController::class, 'show']);
    Route::get('/campeonatos/{id}/categorias/{catId}/ranking', [CampeonatoPublicController::class, 'ranking']);
    Route::get('/campeonatos/{id}/fechas', [CampeonatoPublicController::class, 'fechas']);

    // Patrocinadores publicos
    Route::get('/patrocinadores', [PatrocinadorPublicController::class, 'index']);
    Route::get('/patrocinadores/principales', [PatrocinadorPublicController::class, 'principales']);
});

// Auth
Route::prefix('v1/auth')->middleware('web')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

// Admin (auth required)
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);

    // Campeonatos
    Route::get('/campeonatos', [CampeonatoController::class, 'index']);
    Route::post('/campeonatos', [CampeonatoController::class, 'store']);
    Route::put('/campeonatos/{campeonato}', [CampeonatoController::class, 'update']);
    Route::delete('/campeonatos/{campeonato}', [CampeonatoController::class, 'destroy']);
    Route::post('/campeonatos/{campeonato}/activar', [CampeonatoController::class, 'activar']);
    Route::post('/campeonatos/{campeonato}/finalizar', [CampeonatoController::class, 'finalizar']);

    // Patrocinadores admin
    Route::get('/patrocinadores', [PatrocinadorController::class, 'index']);
    Route::post('/patrocinadores', [PatrocinadorController::class, 'store']);
    Route::get('/patrocinadores/{patrocinador}', [PatrocinadorController::class, 'show']);
    Route::put('/patrocinadores/{patrocinador}', [PatrocinadorController::class, 'update']);
    Route::delete('/patrocinadores/{patrocinador}', [PatrocinadorController::class, 'destroy']);
    Route::post('/patrocinadores/{patrocinador}/logo', [PatrocinadorController::class, 'uploadLogo']);
    Route::get('/patrocinadores/{patrocinador}/contratos', [PatrocinadorController::class, 'contratos']);
    Route::post('/patrocinadores/{patrocinador}/contratos', [PatrocinadorController::class, 'storeContrato']);
    Route::put('/contratos-patrocinio/{contrato}', [PatrocinadorController::class, 'updateContrato']);
    Route::delete('/contratos-patrocinio/{contrato}', [PatrocinadorController::class, 'destroyContrato']);
    Route::get('/contratos-patrocinio/{contrato}/pagos', [PatrocinadorController::class, 'pagos']);
    Route::post('/contratos-patrocinio/{contrato}/pagos', [PatrocinadorController::class, 'storePago']);
    Route::put('/pagos-patrocinio/{pago}', [PatrocinadorController::class, 'updatePago']);
    Route::delete('/pagos-patrocinio/{pago}', [PatrocinadorController::class, 'destroyPago']);
    Route::get('/patrocinadores-reporte', [PatrocinadorController::class, 'reporteAnual']);

    // Categorias catalogo
    Route::get('/categorias', [CategoriaController::class, 'index']);
    Route::post('/categorias', [CategoriaController::class, 'store']);
    Route::put('/categorias/{categoria}', [CategoriaController::class, 'update']);
    Route::delete('/categorias/{categoria}', [CategoriaController::class, 'destroy']);

    // Fechas
    Route::get('/fechas', [FechaController::class, 'index']);
    Route::post('/fechas', [FechaController::class, 'store']);
    Route::put('/fechas/{fecha}', [FechaController::class, 'update']);
    Route::delete('/fechas/{fecha}', [FechaController::class, 'destroy']);
    Route::post('/fechas/{fecha}/configurar', [FechaController::class, 'configurar']);
    Route::post('/fechas/{fecha}/activar', [FechaController::class, 'activar']);
    Route::post('/fechas/{fecha}/finalizar', [FechaController::class, 'finalizar']);
    Route::post('/fechas/{fecha}/desbloquear', [FechaController::class, 'desbloquear']);
    Route::get('/fechas/{fecha}/export-csv', [FechaController::class, 'exportCsv']);
    Route::get('/campeonatos/{campeonato}/export-csv', [CampeonatoController::class, 'exportCsv']);

    // Fecha categorias
    Route::post('/fechas/{fecha}/categorias', [FechaController::class, 'addCategoria']);
    Route::put('/fecha-categorias/{fechaCategoria}', [FechaController::class, 'updateCategoria']);
    Route::delete('/fecha-categorias/{fechaCategoria}', [FechaController::class, 'removeCategoria']);

    // Tripulaciones
    Route::post('/fecha-categorias/{fechaCategoria}/tripulaciones', [TripulacionController::class, 'store']);
    Route::put('/tripulaciones/{tripulacion}', [TripulacionController::class, 'update']);
    Route::delete('/tripulaciones/{tripulacion}', [TripulacionController::class, 'destroy']);
    Route::post('/tripulaciones/{tripulacion}/salio-a-pista', [TripulacionController::class, 'salioAPista']);
    Route::post('/tripulaciones/{tripulacion}/confirmar-largo-tanda', [TripulacionController::class, 'confirmarLargoTanda']);
    Route::post('/tripulaciones/{tripulacion}/reincorporar', [TripulacionController::class, 'reincorporar']);
    Route::post('/tripulaciones/{tripulacion}/abandonar', [TripulacionController::class, 'abandonar']);
    Route::put('/tripulaciones/{tripulacion}/puntos', [TripulacionController::class, 'asignarPuntos']);

    // Vueltas y tramos
    Route::put('/tripulaciones/{tripulacion}/vueltas/{numeroVuelta}/tramos/{letra}', [VueltaController::class, 'upsertTramo']);
    Route::put('/tramos/{tramo}', [VueltaController::class, 'updateTramo']);
    Route::post('/vueltas/{vuelta}/confirmar', [VueltaController::class, 'confirmarVuelta']);
    Route::post('/vueltas/{vuelta}/nula', [VueltaController::class, 'marcarNula']);
    Route::post('/vueltas/{vuelta}/desnula', [VueltaController::class, 'desmarcarNula']);

    // Tiempos muertos
    Route::post('/tramos/{tramo}/tiempos-muertos', [VueltaController::class, 'storeTiempoMuerto']);
    Route::delete('/tiempos-muertos/{tiempoMuerto}', [VueltaController::class, 'destroyTiempoMuerto']);

    // Dispositivos cronometro
    Route::get('/dispositivos-cronometro', [CronometroController::class, 'index']);
    Route::get('/dispositivos-cronometro/{dispositivo}', [CronometroController::class, 'show']);
    Route::put('/dispositivos-cronometro/{dispositivo}', [CronometroController::class, 'update']);
    Route::get('/dispositivos-cronometro/{dispositivo}/token', [CronometroController::class, 'verToken']);
    Route::post('/dispositivos-cronometro/{dispositivo}/regenerar-token', [CronometroController::class, 'regenerarToken']);
    Route::post('/cronometro/{codigo}/comando', [CronometroController::class, 'enviarComando']);
    Route::get('/cronometro/estado', [CronometroController::class, 'estadoCronometraje']);
    Route::post('/cronometro/armar-largada', [CronometroController::class, 'armarLargada']);
    Route::post('/cronometro/armar-llegada', [CronometroController::class, 'armarLlegada']);
    Route::post('/cronometro/armar-largada-doble', [CronometroController::class, 'armarLargadaDoble']);
    Route::post('/cronometro/armar-llegada-doble', [CronometroController::class, 'armarLlegadaDoble']);
    Route::get('/cronometro/estado-doble', [CronometroController::class, 'estadoDoble']);
    Route::post('/cronometro/reset-doble', [CronometroController::class, 'resetDoble']);
    Route::post('/cronometro/reset-a', [CronometroController::class, 'resetPistaA']);
    Route::post('/cronometro/reset-b', [CronometroController::class, 'resetPistaB']);
    Route::post('/cronometro/reset-cronometraje', [CronometroController::class, 'resetCronometraje']);
    Route::get('/cronometro/eventos-pendientes', [CronometroController::class, 'eventosPendientes']);
    Route::get('/cronometro/{codigo}/estado', [CronometroController::class, 'estadoCronometro']);
    Route::get('/dispositivos/{codigo}/logs', [CronometroController::class, 'verLogs']);
});

// Sync config: bajar fecha desde nube (autenticado con SYNC_SECRET)
Route::get('/v1/sync/fecha/{fechaId}/export', [SyncController::class, 'exportarFecha']);
Route::post('/v1/sync/fecha/{fechaId}/bajar', [SyncController::class, 'bajarFechaDesdeNube'])->middleware('auth:sanctum');

