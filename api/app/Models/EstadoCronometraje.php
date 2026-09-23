<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstadoCronometraje extends Model
{
    protected $table = 'estado_cronometraje';

    protected $fillable = [
        'tramo', 'tripulacion_id', 'vuelta_numero', 'fase', 'largada_at',
        'crono_codigo', 'sensor_largada_codigo', 'sensor_llegada_codigo', 'tramo_letra',
        'ultimo_tiempo_ms', 'ultimo_tripulacion_id',
    ];

    protected $casts = [
        'largada_at' => 'datetime',
    ];

    public function tripulacion(): BelongsTo
    {
        return $this->belongsTo(Tripulacion::class);
    }

    /**
     * Singleton for pista simple (tramo A, id=1).
     */
    public static function singleton(): self
    {
        return self::firstOrCreate(['id' => 1], ['tramo' => 'A', 'fase' => 'idle']);
    }

    /**
     * Get estado for a specific tramo (A or B).
     */
    public static function porTramo(string $tramo): self
    {
        $id = $tramo === 'B' ? 2 : 1;
        return self::firstOrCreate(['id' => $id], [
            'tramo' => $tramo,
            'fase' => 'idle',
            'crono_codigo' => $tramo === 'B' ? 'crono-b' : 'crono-a',
            'sensor_largada_codigo' => $tramo === 'B' ? 'sensor-b' : 'sensor-a',
            'sensor_llegada_codigo' => $tramo === 'B' ? 'sensor-b' : 'sensor-a',
            'tramo_letra' => $tramo,
        ]);
    }

    public function armarLargada(string $tripId, int $vuelta, string $cronoCodigo, string $sensorLargada, string $sensorLlegada, string $tramoLetra): void
    {
        $this->update([
            'tripulacion_id' => $tripId,
            'vuelta_numero' => $vuelta,
            'fase' => 'esperando_largada',
            'largada_at' => null,
            'crono_codigo' => $cronoCodigo,
            'sensor_largada_codigo' => $sensorLargada,
            'sensor_llegada_codigo' => $sensorLlegada,
            'tramo_letra' => $tramoLetra,
        ]);
    }

    public function armarLlegada(): void
    {
        $this->update(['fase' => 'esperando_llegada']);
    }

    public function registrarLargada($timestamp = null): void
    {
        $this->update([
            'fase' => 'corriendo',
            'largada_at' => $timestamp ?? now(),
        ]);
    }

    public function resetear(?int $tiempoMs = null): void
    {
        $this->update([
            'ultimo_tiempo_ms' => $tiempoMs,
            'ultimo_tripulacion_id' => $this->tripulacion_id,
            'tripulacion_id' => null,
            'vuelta_numero' => null,
            'fase' => 'idle',
            'largada_at' => null,
        ]);
    }

    public function limpiarUltimoTiempo(): void
    {
        $this->update(['ultimo_tiempo_ms' => null, 'ultimo_tripulacion_id' => null]);
    }
}
