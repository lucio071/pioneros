<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ComandoCronometro;
use App\Models\EventoCronometro;
use App\Models\EstadoCronometraje;
use App\Models\DispositivoCronometro;
use App\Models\DispositivoLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CronometroController extends Controller
{
    // ===== Endpoints dispositivo (auth.dispositivo middleware) =====

    public function heartbeat(Request $request): JsonResponse
    {
        $dispositivo = $request->get('dispositivo');

        $dispositivo->registrarHeartbeat(
            $request->input('rssi'),
            $request->input('voltaje_mv'),
            $request->input('uptime_sec'),
        );

        return response()->json([
            'ok' => true,
            'dispositivo' => $dispositivo->codigo,
            'server_time' => now()->toISOString(),
        ]);
    }

    public function comandosPendientes(Request $request): JsonResponse
    {
        $dispositivo = $request->get('dispositivo');

        // Heartbeat via query params (cronos mandan datos en el mismo GET)
        if ($request->has('rssi')) {
            $dispositivo->update([
                'ultimo_visto_at' => now(),
                'ultimo_rssi' => (int) $request->query('rssi', 0),
                'ultimo_voltaje_mv' => (int) $request->query('voltaje_mv', 0),
                'ultimo_uptime_sec' => (int) $request->query('uptime_sec', 0),
            ]);
        }

        // Long polling: wait=N seconds (max 10)
        $waitSec = min((int) $request->query('wait', 0), 10);
        if ($waitSec > 0) {
            set_time_limit($waitSec + 5);
        }

        $deadline = microtime(true) + $waitSec;

        do {
            $comandos = ComandoCronometro::where('dispositivo_id', $dispositivo->id)
                ->where(function ($q) {
                    $q->where('estado', 'pendiente')
                       ->orWhere(function ($q2) {
                           $q2->where('estado', 'entregado')
                               ->where('entregado_at', '<', now()->subSeconds(1));
                       });
                })
                ->orderBy('created_at')
                ->get();

            if ($comandos->isNotEmpty() || microtime(true) >= $deadline) {
                break;
            }

            usleep(25000); // 25ms
        } while (true);

        foreach ($comandos as $cmd) {
            $cmd->update(['estado' => 'entregado', 'entregado_at' => now()]);
        }

        return response()->json([
            'comandos' => $comandos->map(fn($c) => [
                'id' => $c->id,
                'tipo' => $c->tipo,
                'payload' => $c->payload,
            ]),
        ]);
    }

    public function confirmarComando(Request $request, string $id): JsonResponse
    {
        $dispositivo = $request->get('dispositivo');

        $comando = ComandoCronometro::where('id', $id)
            ->where('dispositivo_id', $dispositivo->id)
            ->firstOrFail();

        $comando->update(['estado' => 'ejecutado', 'ejecutado_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /**
     * Registrar evento de sensor.
     * Busca en TODAS las filas de estado_cronometraje cual matchea el sensor.
     */
    public function registrarEvento(Request $request): JsonResponse
    {
        $dispositivo = $request->get('dispositivo');

        $data = $request->validate([
            'tipo' => 'required|string',
            'tramo' => 'nullable|in:A,B',
        ]);

        $evento = EventoCronometro::create([
            'dispositivo_id' => $dispositivo->id,
            'tipo' => $data['tipo'],
            'tramo' => $data['tramo'] ?? $dispositivo->tramo,
            'timestamp_servidor' => now(),
            'procesado' => false,
        ]);

        $resultado = ['accion' => 'registrado'];

        if (in_array($data['tipo'], ['sensor_disparado', 'cruce'])) {
            $sensorCodigo = $dispositivo->codigo;

            // Buscar en todos los estados (tramo A y B) cual espera este sensor
            $estados = EstadoCronometraje::all();

            foreach ($estados as $estado) {
                if ($estado->fase === 'esperando_largada' && $sensorCodigo === $estado->sensor_largada_codigo) {
                    // LARGADA detectada
                    $estado->registrarLargada();
                    $evento->update(['procesado' => true]);

                    // Marcar vuelta como largada
                    if ($estado->tripulacion_id && $estado->vuelta_numero) {
                        $tripLargo = \App\Models\Tripulacion::find($estado->tripulacion_id);
                        if ($tripLargo) {
                            $v = $tripLargo->vueltas()->firstOrCreate(
                                ['numero_vuelta' => $estado->vuelta_numero],
                                ['fase' => 'clasificacion', 'nula' => false, 'confirmada' => false]
                            );
                            if (!$v->largo) {
                                $v->update(['largo' => true]);
                                $tripLargo->fechaCategoria->fecha->incrementVersion();
                            }
                        }
                    }

                    // Mandar START al cronometro
                    $crono = DispositivoCronometro::where('codigo', $estado->crono_codigo)->first();
                    if ($crono) {
                        ComandoCronometro::create([
                            'dispositivo_id' => $crono->id,
                            'tipo' => 'start',
                        ]);
                    }

                    $resultado = ['accion' => 'largada_detectada', 'tramo' => $estado->tramo, 'crono' => $estado->crono_codigo];
                    break;

                } elseif ($estado->fase === 'esperando_llegada' && $sensorCodigo === $estado->sensor_llegada_codigo) {
                    // LLEGADA detectada
                    if ($estado->largada_at) {
                        $tiempoMs = (int) abs(round(now()->diffInMilliseconds($estado->largada_at)));

                        // Mandar STOP + SET_TIEMPO al cronometro de esta pista
                        $crono = DispositivoCronometro::where('codigo', $estado->crono_codigo)->first();
                        if ($crono) {
                            ComandoCronometro::create(['dispositivo_id' => $crono->id, 'tipo' => 'stop']);
                            ComandoCronometro::create(['dispositivo_id' => $crono->id, 'tipo' => 'set_tiempo', 'payload' => ['ms' => $tiempoMs]]);
                        }

                        // Guardar tramo
                        if ($estado->tripulacion_id && $estado->vuelta_numero) {
                            $trip = \App\Models\Tripulacion::find($estado->tripulacion_id);
                            if ($trip) {
                                $vuelta = $trip->vueltas()->firstOrCreate(
                                    ['numero_vuelta' => $estado->vuelta_numero],
                                    ['fase' => 'clasificacion', 'nula' => false, 'confirmada' => false]
                                );
                                $vuelta->tramos()->updateOrCreate(
                                    ['letra' => $estado->tramo_letra],
                                    ['tiempo_ms' => $tiempoMs, 'estacas' => 0, 'cintas' => 0, 'fuente_tiempo' => 'sensor']
                                );
                                $trip->actualizarEstado();
                                $trip->fechaCategoria->evaluarTransicionFase();
                                $trip->fechaCategoria->fecha->incrementVersion();
                            }
                        }

                        $evento->update(['procesado' => true]);
                        $estado->resetear($tiempoMs);
                        $resultado = ['accion' => 'llegada_detectada', 'tramo' => $estado->tramo, 'tiempo_ms' => $tiempoMs];
                        break;
                    }
                }
            }
        }

        return response()->json([
            'ok' => true,
            'evento_id' => $evento->id,
            'timestamp' => $evento->timestamp_servidor->toISOString(),
            'resultado' => $resultado,
        ]);
    }

    public function eventosPendientes(): JsonResponse
    {
        $eventos = EventoCronometro::where('procesado', false)
            ->orderBy('timestamp_servidor')
            ->with('dispositivo')
            ->get();

        return response()->json(['data' => $eventos]);
    }

    // ===== Endpoints admin (auth Sanctum) =====

    /**
     * Estado cronometraje pista simple (singleton tramo A).
     */
    public function estadoCronometraje(): JsonResponse
    {
        $estado = EstadoCronometraje::singleton();
        return response()->json([
            'data' => [
                'fase' => $estado->fase,
                'tripulacion_id' => $estado->tripulacion_id,
                'vuelta_numero' => $estado->vuelta_numero,
                'largada_at' => $estado->largada_at?->toISOString(),
                'crono_codigo' => $estado->crono_codigo,
                'sensor_largada_codigo' => $estado->sensor_largada_codigo,
                'sensor_llegada_codigo' => $estado->sensor_llegada_codigo,
                'tramo_letra' => $estado->tramo_letra,
                'tiempo_corriendo_ms' => $estado->largada_at ? (int) abs(round(now()->diffInMilliseconds($estado->largada_at))) : null,
                'ultimo_tiempo_ms' => $estado->ultimo_tiempo_ms,
                'ultimo_tripulacion_id' => $estado->ultimo_tripulacion_id,
            ],
        ]);
    }

    /**
     * Estado cronometraje pista doble (tramo A y B).
     */
    public function estadoDoble(): JsonResponse
    {
        $estadoA = EstadoCronometraje::porTramo('A');
        $estadoB = EstadoCronometraje::porTramo('B');

        $format = function ($estado) {
            return [
                'tramo' => $estado->tramo,
                'fase' => $estado->fase,
                'tripulacion_id' => $estado->tripulacion_id,
                'vuelta_numero' => $estado->vuelta_numero,
                'largada_at' => $estado->largada_at?->toISOString(),
                'crono_codigo' => $estado->crono_codigo,
                'tiempo_corriendo_ms' => $estado->largada_at ? (int) abs(round(now()->diffInMilliseconds($estado->largada_at))) : null,
                'ultimo_tiempo_ms' => $estado->ultimo_tiempo_ms,
                'ultimo_tripulacion_id' => $estado->ultimo_tripulacion_id,
            ];
        };

        return response()->json([
            'data' => [
                'pista_a' => $format($estadoA),
                'pista_b' => $format($estadoB),
            ],
        ]);
    }

    /**
     * Armar largada pista simple.
     */
    public function armarLargada(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tripulacion_id' => 'required|uuid|exists:tripulaciones,id',
            'vuelta_numero' => 'required|integer|min:1',
            'crono_codigo' => 'required|string',
            'sensor_largada_codigo' => 'required|string',
            'sensor_llegada_codigo' => 'required|string',
            'tramo_letra' => 'required|in:A,B',
        ]);

        $estado = EstadoCronometraje::singleton();
        $estado->armarLargada(
            $data['tripulacion_id'], $data['vuelta_numero'],
            $data['crono_codigo'], $data['sensor_largada_codigo'],
            $data['sensor_llegada_codigo'], $data['tramo_letra']
        );

        // Habilitar sensor de largada
        $this->habilitarSensor($data['sensor_largada_codigo'], $request->user()->id);

        // Enviar numero al crono
        $this->enviarTripulacionACrono($data['crono_codigo'], $data['tripulacion_id'], $request->user()->id);

        // Activar semaforo
        $this->activarSemaforo($request->user()->id);

        return response()->json(['data' => ['fase' => 'esperando_largada']]);
    }

    /**
     * Armar largada pista doble: 2 tripulaciones, 2 pistas, 1 semaforo.
     */
    public function armarLargadaDoble(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tripulacion_a_id' => 'required|uuid|exists:tripulaciones,id',
            'tripulacion_b_id' => 'nullable|uuid|exists:tripulaciones,id',
            'vuelta_numero' => 'required|integer|min:1',
            'tramo_letra_a' => 'required|in:A,B',
            'tramo_letra_b' => 'required|in:A,B',
        ]);

        $userId = $request->user()->id;

        // Pista A: crono-a, sensor-a
        $estadoA = EstadoCronometraje::porTramo('A');
        $estadoA->armarLargada(
            $data['tripulacion_a_id'], $data['vuelta_numero'],
            'crono-a', 'sensor-a', 'sensor-a', $data['tramo_letra_a']
        );
        $this->habilitarSensor('sensor-a', $userId);
        $this->enviarTripulacionACrono('crono-a', $data['tripulacion_a_id'], $userId);

        // Pista B: crono-b, sensor-b
        if (!empty($data['tripulacion_b_id'])) {
            $estadoB = EstadoCronometraje::porTramo('B');
            $estadoB->armarLargada(
                $data['tripulacion_b_id'], $data['vuelta_numero'],
                'crono-b', 'sensor-b', 'sensor-b', $data['tramo_letra_b']
            );
            $this->habilitarSensor('sensor-b', $userId);
            $this->enviarTripulacionACrono('crono-b', $data['tripulacion_b_id'], $userId);
        }

        // Semaforo
        $this->activarSemaforo($userId);

        \Log::info('armarLargadaDoble', [
            'trip_a' => $data['tripulacion_a_id'],
            'trip_b' => $data['tripulacion_b_id'] ?? 'NULL',
            'vuelta' => $data['vuelta_numero'],
        ]);

        return response()->json(['data' => ['fase' => 'esperando_largada']]);
    }

    /**
     * Armar llegada pista simple.
     */
    public function armarLlegada(Request $request): JsonResponse
    {
        $estado = EstadoCronometraje::singleton();

        if (!in_array($estado->fase, ['corriendo', 'esperando_llegada'])) {
            return response()->json(['message' => 'No hay corrida en curso'], 422);
        }

        $estado->armarLlegada();
        $this->habilitarSensor($estado->sensor_llegada_codigo, $request->user()->id);

        return response()->json(['data' => ['fase' => 'esperando_llegada']]);
    }

    /**
     * Armar llegada pista doble: habilita sensor-a y sensor-b.
     */
    public function armarLlegadaDoble(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $estadoA = EstadoCronometraje::porTramo('A');
        $estadoB = EstadoCronometraje::porTramo('B');

        $armados = 0;

        if (in_array($estadoA->fase, ['corriendo', 'esperando_llegada'])) {
            $estadoA->armarLlegada();
            $this->habilitarSensor('sensor-a', $userId);
            $armados++;
        }

        if (in_array($estadoB->fase, ['corriendo', 'esperando_llegada'])) {
            $estadoB->armarLlegada();
            $this->habilitarSensor('sensor-b', $userId);
            $armados++;
        }

        if ($armados === 0) {
            return response()->json(['message' => 'No hay corrida en curso'], 422);
        }

        return response()->json(['data' => ['fase' => 'esperando_llegada', 'pistas_armadas' => $armados]]);
    }

    /**
     * Reset cronometraje pista simple.
     */
    public function resetCronometraje(): JsonResponse
    {
        EstadoCronometraje::singleton()->resetear();
        return response()->json(['data' => ['fase' => 'idle']]);
    }

    /**
     * Reset cronometraje pista doble (ambas pistas).
     */
    public function resetDoble(): JsonResponse
    {
        EstadoCronometraje::porTramo('A')->resetear();
        EstadoCronometraje::porTramo('B')->resetear();
        return response()->json(['data' => ['fase' => 'idle']]);
    }

    /**
     * Reset solo pista A (sin afectar pista B).
     */
    public function resetPistaA(): JsonResponse
    {
        EstadoCronometraje::porTramo('A')->resetear();
        return response()->json(['data' => ['pista' => 'A', 'fase' => 'idle']]);
    }

    /**
     * Reset solo pista B (sin afectar pista A).
     */
    public function resetPistaB(): JsonResponse
    {
        EstadoCronometraje::porTramo('B')->resetear();
        return response()->json(['data' => ['pista' => 'B', 'fase' => 'idle']]);
    }


    // ===== Helpers privados =====

    private function habilitarSensor(string $sensorCodigo, ?string $userId): void
    {
        $sensor = DispositivoCronometro::where('codigo', $sensorCodigo)->first();
        if ($sensor) {
            ComandoCronometro::create([
                'dispositivo_id' => $sensor->id,
                'tipo' => 'habilitar_sensor',
                'payload' => ['duracion_seg' => 20],
                'creado_por_user_id' => $userId,
            ]);
        }
    }

    private function activarSemaforo(?string $userId): void
    {
        $semaforo = DispositivoCronometro::where('codigo', 'semaforo')->first();
        if ($semaforo) {
            ComandoCronometro::create([
                'dispositivo_id' => $semaforo->id,
                'tipo' => 'semaforo_largada',
                'payload' => [],
                'creado_por_user_id' => $userId,
            ]);
        }
    }

    private function enviarTripulacionACrono(string $cronoCodigo, string $tripId, ?string $userId): void
    {
        $crono = DispositivoCronometro::where('codigo', $cronoCodigo)->first();
        $trip = \App\Models\Tripulacion::find($tripId);
        if ($crono && $trip) {
            ComandoCronometro::create([
                'dispositivo_id' => $crono->id,
                'tipo' => 'set_tripulacion',
                'payload' => ['numero' => (int) $trip->numero],
                'creado_por_user_id' => $userId,
            ]);
        }
    }

    /**
     * Registrar log de debug desde dispositivo.
     */
    public function registrarLog(Request $request): JsonResponse
    {
        $dispositivo = $request->get('dispositivo');

        $data = $request->validate([
            'mensaje' => 'required|string|max:4000',
            'uptime_ms' => 'nullable|integer|min:0',
        ]);

        $log = DispositivoLog::create([
            'dispositivo_id' => $dispositivo->id,
            'mensaje' => $data['mensaje'],
            'uptime_ms' => $data['uptime_ms'] ?? null,
            'created_at' => now(),
        ]);

        return response()->json(['id' => $log->id], 201);
    }

    /**
     * Admin: ver logs de un dispositivo.
     */
    public function verLogs(string $codigo)
    {
        $dispositivo = DispositivoCronometro::where('codigo', $codigo)->firstOrFail();

        $logs = DispositivoLog::where('dispositivo_id', $dispositivo->id)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return view('admin.dispositivo-logs', compact('dispositivo', 'logs'));
    }

    // ===== CRUD dispositivos =====

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => DispositivoCronometro::orderBy('codigo')->get(),
        ]);
    }

    public function show(DispositivoCronometro $dispositivo): JsonResponse
    {
        return response()->json(['data' => $dispositivo]);
    }

    public function update(Request $request, DispositivoCronometro $dispositivo): JsonResponse
    {
        $data = $request->validate([
            'nombre' => 'sometimes|string|max:255',
            'activo' => 'sometimes|boolean',
        ]);

        $dispositivo->update($data);

        return response()->json(['data' => $dispositivo]);
    }

    public function verToken(DispositivoCronometro $dispositivo): JsonResponse
    {
        return response()->json([
            'data' => [
                'codigo' => $dispositivo->codigo,
                'api_token' => $dispositivo->api_token,
            ],
        ]);
    }

    public function regenerarToken(DispositivoCronometro $dispositivo): JsonResponse
    {
        $dispositivo->update(['api_token' => Str::random(60)]);

        return response()->json([
            'data' => [
                'codigo' => $dispositivo->codigo,
                'api_token' => $dispositivo->api_token,
            ],
        ]);
    }

    /**
     * Enviar comando a un dispositivo por codigo.
     */
    public function enviarComando(Request $request, string $codigo): JsonResponse
    {
        $dispositivo = DispositivoCronometro::where('codigo', $codigo)->firstOrFail();

        if (!$dispositivo->activo) {
            return response()->json(['message' => 'Dispositivo inactivo'], 422);
        }

        if ($dispositivo->estado === 'offline' || $dispositivo->estado === 'nunca') {
            return response()->json(['message' => "Cronometro {$codigo} esta offline"], 503);
        }

        $data = $request->validate([
            'tipo' => 'required|in:start,stop,reset,set_tiempo,set_tripulacion,habilitar_sensor,semaforo_largada',
            'payload' => 'nullable|array',
        ]);

        $comando = ComandoCronometro::create([
            'dispositivo_id' => $dispositivo->id,
            'tipo' => $data['tipo'],
            'payload' => $data['payload'] ?? null,
            'creado_por_user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => $comando]);
    }

    /**
     * Estado de un dispositivo por codigo.
     */
    public function estadoCronometro(string $codigo): JsonResponse
    {
        $dispositivo = DispositivoCronometro::where('codigo', $codigo)->firstOrFail();

        $ultimoComando = ComandoCronometro::where('dispositivo_id', $dispositivo->id)
            ->orderByDesc('created_at')
            ->first();

        return response()->json([
            'data' => [
                'codigo' => $dispositivo->codigo,
                'online' => $dispositivo->estado === 'online',
                'ultimo_visto_hace_seg' => $dispositivo->ultimo_visto_at
                    ? $dispositivo->ultimo_visto_at->diffInSeconds(now())
                    : null,
                'ultimo_comando' => $ultimoComando ? [
                    'tipo' => $ultimoComando->tipo,
                    'estado' => $ultimoComando->estado,
                    'created_at' => $ultimoComando->created_at->toISOString(),
                ] : null,
            ],
        ]);
    }
}
