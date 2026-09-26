<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tripulacion extends Model
{
    use HasUuids;

    protected $table = 'tripulaciones';

    protected $fillable = [
        'fecha_categoria_id',
        'numero', 'nombre', 'piloto', 'copiloto',
        'estado', 'orden_largada', 'puntos',
    ];

    protected $casts = [
        'orden_largada' => 'integer',
        'puntos' => 'integer',
    ];

    public function fechaCategoria(): BelongsTo
    {
        return $this->belongsTo(FechaCategoria::class);
    }

    public function vueltas(): HasMany
    {
        return $this->hasMany(Vuelta::class);
    }

    /**
     * Mejor tiempo de clasificación (mínimo entre vueltas válidas).
     */
    public function mejorVueltaMs(FechaCategoria $fc): ?int
    {
        $vueltas = $this->vueltasClasifValidas($fc);
        if ($vueltas->isEmpty()) return null;

        $totals = $vueltas->map(fn($v) => $v->calcularTotal($fc->penal_estaca_seg, $fc->penal_cinta_seg, $fc->penal_estirada_seg ?? 60))
            ->filter(fn($t) => $t !== null);

        return $totals->isEmpty() ? null : $totals->min();
    }

    /**
     * Número de la mejor vuelta de clasificación.
     */
    public function mejorVueltaNumero(FechaCategoria $fc): ?int
    {
        $vueltas = $this->vueltasClasifValidas($fc);
        if ($vueltas->isEmpty()) return null;

        $best = null;
        $bestNum = null;
        foreach ($vueltas as $v) {
            $total = $v->calcularTotal($fc->penal_estaca_seg, $fc->penal_cinta_seg, $fc->penal_estirada_seg ?? 60);
            if ($total !== null && ($best === null || $total < $best)) {
                $best = $total;
                $bestNum = $v->numero_vuelta;
            }
        }

        return $bestNum;
    }

    /**
     * Vueltas de clasificación válidas (no nulas, con tramos completos según tipo de pista).
     */
    private function vueltasClasifValidas(FechaCategoria $fc)
    {
        $vueltas = $this->vueltas()
            ->where('fase', 'clasificacion')
            ->where('nula', false)
            ->with('tramos.tiemposMuertos')
            ->get();

        // Pista doble: solo cuentan vueltas con AMBOS tramos A y B
        $tipoPista = $fc->tipo_pista ?? $fc->fecha->tipo_pista ?? 'simple';
        if ($tipoPista === 'doble') {
            $vueltas = $vueltas->filter(function ($v) {
                $letras = $v->tramos->pluck('letra')->toArray();
                return in_array('A', $letras) && in_array('B', $letras);
            });
        }

        return $vueltas;
    }

    /**
     * Actualiza el estado basado en las vueltas cargadas.
     */
    public function actualizarEstado(): void
    {
        // No cambiar estado si fue abandonado manualmente
        if ($this->estado === 'abandonado') return;

        $fc = $this->fechaCategoria;
        $fecha = $fc->fecha;
        $vueltas = $this->vueltas()->where('fase', 'clasificacion')->get();

        // Si todas las vueltas son nulas
        if ($vueltas->isNotEmpty() && $vueltas->every(fn($v) => $v->nula)) {
            $this->update(['estado' => 'nula']);
            return;
        }

        // Si tiene todas las vueltas de clasificación
        if ($vueltas->count() >= $fecha->vueltas_clasificacion) {
            $this->update(['estado' => 'completada']);
            return;
        }

        // Si no está en_pista, dejar como inscripta
        if ($this->estado !== 'en_pista') {
            // no cambiar
        }
    }
}
