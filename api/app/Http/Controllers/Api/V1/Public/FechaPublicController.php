<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Fecha;
use App\Models\FechaCategoria;
use App\Models\CategoriaCatalogo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FechaPublicController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'app' => 'pioneros-api']);
    }

    public function activa(Request $request): JsonResponse
    {
        $fecha = Fecha::activa()
            ->with('fechaCategorias.categoriaCatalogo')
            ->withCount('tripulaciones')
            ->first();

        if (!$fecha) {
            return response()->json(['data' => null]);
        }

        $etag = '"fecha-' . $fecha->id . '-v' . $fecha->version . '"';
        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304);
        }

        return response()->json(['data' => $this->formatFecha($fecha)])
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=2');
    }

    public function ranking(Request $request, string $fechaId, string $categoriaId): JsonResponse
    {
        $fecha = Fecha::findOrFail($fechaId);
        $fc = FechaCategoria::where('id', $categoriaId)
            ->where('fecha_id', $fechaId)
            ->with('categoriaCatalogo')
            ->firstOrFail();

        $etag = '"ranking-' . $fc->id . '-v' . $fecha->version . '"';
        if ($request->header('If-None-Match') === $etag) {
            return response()->json(null, 304);
        }

        $trips = $fc->tripulaciones()
            ->with('vueltas.tramos.tiemposMuertos')
            ->orderBy('orden_largada')
            ->get();

        // Build ranking data for each tripulacion
        $tripData = $trips->map(function ($t) use ($fc, $fecha) {
            $vueltasClasif = $t->vueltas->where('fase', 'clasificacion')->sortBy('numero_vuelta');
            $vueltaFinal = $t->vueltas->where('numero_vuelta', 99)->first();

            $vueltasDetalle = $vueltasClasif->map(fn($v) => $this->formatVuelta($v, $fc));
            $mejorMs = $t->mejorVueltaMs($fc);
            $mejorNum = $t->mejorVueltaNumero($fc);

            // DNF = abandonado, o sin ninguna vuelta valida completa
            // Clasificado = al menos 1 vuelta valida (no nula, con tramos completos)
            $todasNulas = $vueltasClasif->isNotEmpty() && $vueltasClasif->every(fn($v) => $v->nula);
            $esDnf = $t->estado === 'nula'
                || $t->estado === 'abandonado'
                || ($todasNulas && $vueltasClasif->isNotEmpty())
                || ($fecha->estado === 'finalizada' && $mejorMs === null);

            return [
                'id' => $t->id,
                'numero' => $t->numero,
                'nombre' => $t->nombre,
                'piloto' => $t->piloto,
                'copiloto' => $t->copiloto,
                'estado' => $t->estado,
                'orden_largada' => $t->orden_largada,
                'vueltas' => $vueltasDetalle->values(),
                'mejor_vuelta_ms' => $mejorMs,
                'mejor_vuelta_numero' => $mejorNum,
                'vuelta_final' => $vueltaFinal ? $this->formatVuelta($vueltaFinal, $fc) : null,
                'puntos' => $t->puntos ?? 0,
                'dnf' => $esDnf,
            ];
        });

        // Separate into ranking, en_curso, dnf
        $conTiempo = $tripData->filter(fn($t) => $t['mejor_vuelta_ms'] !== null && !$t['dnf'])
            ->sortBy('mejor_vuelta_ms')->values();
        $sinTiempo = $tripData->filter(fn($t) => $t['mejor_vuelta_ms'] === null && !$t['dnf'])->values();
        $dnf = $tripData->filter(fn($t) => $t['dnf'])->values();

        $ranked = $conTiempo->map(function ($t, $i) use ($conTiempo) {
            $t['posicion'] = $i + 1;
            $t['diferencia'] = $i === 0 ? 0 : $t['mejor_vuelta_ms'] - $conTiempo->first()['mejor_vuelta_ms'];
            return $t;
        });

        // Final ranking (independent)
        $finalRanking = null;
        if ($fc->fase === 'final' || $fc->fase === 'finalizada') {
            $conFinal = $tripData->filter(fn($t) => $t['vuelta_final'] !== null)
                ->sortBy(fn($t) => $t['vuelta_final']['total_vuelta'] ?? PHP_INT_MAX)
                ->values();

            $finalRanking = $conFinal->map(function ($t, $i) use ($conFinal) {
                return [
                    'id' => $t['id'],
                    'numero' => $t['numero'],
                    'nombre' => $t['nombre'],
                    'piloto' => $t['piloto'],
                    'posicion' => $i + 1,
                    'vuelta_final' => $t['vuelta_final'],
                    'diferencia' => $i === 0 ? 0 :
                        ($t['vuelta_final']['total_vuelta'] ?? 0) - ($conFinal->first()['vuelta_final']['total_vuelta'] ?? 0),
                ];
            });
        }

        // Stats
        $stats = [
            'total' => $trips->count(),
            'en_pista' => $trips->where('estado', 'en_pista')->count(),
            'completadas' => $trips->where('estado', 'completada')->count(),
            'nulas' => $trips->where('estado', 'nula')->count(),
            'fase' => $fc->fase,
        ];

        return response()->json([
            'data' => [
                'fecha' => $this->formatFecha($fecha),
                'categoria' => [
                    'id' => $fc->id,
                    'nombre' => $fc->categoriaCatalogo->nombre,
                    'penal_estaca_seg' => $fc->penal_estaca_seg,
                    'penal_cinta_seg' => $fc->penal_cinta_seg,
                    'fase' => $fc->fase,
                ],
                'ranking' => $ranked,
                'en_curso' => $sinTiempo,
                'dnf' => $dnf,
                'final_ranking' => $finalRanking,
                'stats' => $stats,
            ],
        ])
            ->header('ETag', $etag)
            ->header('Cache-Control', 'public, max-age=2');
    }

    /**
     * Top 3 general de la fecha (mejores tiempos cruzando todas las categorias).
     */
    public function top3General(string $fechaId): JsonResponse
    {
        $fecha = Fecha::findOrFail($fechaId);
        $fcs = $fecha->fechaCategorias()->with('categoriaCatalogo', 'tripulaciones.vueltas.tramos.tiemposMuertos')->get();

        $todos = collect();

        foreach ($fcs as $fc) {
            foreach ($fc->tripulaciones as $t) {
                $mejor = $t->mejorVueltaMs($fc);
                if ($mejor !== null) {
                    $todos->push([
                        'tripulacion_id' => $t->id,
                        'numero' => $t->numero,
                        'nombre' => $t->nombre,
                        'piloto' => $t->piloto,
                        'copiloto' => $t->copiloto,
                        'categoria' => $fc->categoriaCatalogo->nombre,
                        'mejor_vuelta_ms' => $mejor,
                    ]);
                }
            }
        }

        $top3 = $todos->sortBy('mejor_vuelta_ms')->values()->take(3)->map(function ($t, $i) {
            $t['posicion'] = $i + 1;
            return $t;
        });

        return response()->json(['data' => $top3]);
    }

    public function categorias(string $fechaId): JsonResponse
    {
        $fecha = Fecha::findOrFail($fechaId);
        $cats = $fecha->fechaCategorias()
            ->with('categoriaCatalogo')
            ->withCount('tripulaciones')
            ->get();

        return response()->json([
            'data' => $cats->map(fn($fc) => [
                'id' => $fc->id,
                'nombre' => $fc->categoriaCatalogo->nombre,
                'slug' => $fc->categoriaCatalogo->slug,
                'penal_estaca_seg' => $fc->penal_estaca_seg,
                'penal_cinta_seg' => $fc->penal_cinta_seg,
                'fase' => $fc->fase,
                'tripulaciones_count' => $fc->tripulaciones_count,
            ]),
        ]);
    }

    public function tripulacionDetalle(string $fechaId, string $tripId): JsonResponse
    {
        $fecha = Fecha::findOrFail($fechaId);
        $trip = $fecha->tripulaciones()->with('vueltas.tramos.tiemposMuertos', 'fechaCategoria.categoriaCatalogo')->findOrFail($tripId);
        $fc = $trip->fechaCategoria;

        return response()->json([
            'data' => [
                'id' => $trip->id,
                'numero' => $trip->numero,
                'nombre' => $trip->nombre,
                'piloto' => $trip->piloto,
                'copiloto' => $trip->copiloto,
                'estado' => $trip->estado,
                'categoria' => $fc->categoriaCatalogo->nombre,
                'mejor_vuelta_ms' => $trip->mejorVueltaMs($fc),
                'vueltas' => $trip->vueltas->sortBy('numero_vuelta')->map(fn($v) => $this->formatVuelta($v, $fc))->values(),
            ],
        ]);
    }

    public function fechasFinalizadas(): JsonResponse
    {
        $fechas = Fecha::where('estado', 'finalizada')
            ->with('fechaCategorias.categoriaCatalogo')
            ->withCount('tripulaciones')
            ->orderByDesc('fecha')
            ->get()
            ->map(fn($f) => $this->formatFecha($f));

        return response()->json(['data' => $fechas]);
    }

    public function catalogoCategorias(): JsonResponse
    {
        return response()->json([
            'data' => CategoriaCatalogo::orderBy('orden')->get(),
        ]);
    }

    private function formatFecha(Fecha $f): array
    {
        return [
            'id' => $f->id,
            'nombre' => $f->nombre,
            'fecha' => $f->fecha->format('Y-m-d'),
            'tipo_pista' => $f->tipo_pista,
            'vueltas_clasificacion' => $f->vueltas_clasificacion,
            'tiene_final' => $f->tiene_final,
            'finalistas_top' => $f->finalistas_top,
            'estado' => $f->estado,
            'version' => $f->version,
            'tripulaciones_count' => $f->tripulaciones_count ?? $f->tripulaciones()->count(),
            'categorias' => $f->fechaCategorias->map(fn($fc) => [
                'id' => $fc->id,
                'nombre' => $fc->categoriaCatalogo->nombre,
                'slug' => $fc->categoriaCatalogo->slug,
                'fase' => $fc->fase,
                'penal_estaca_seg' => $fc->penal_estaca_seg,
                'penal_cinta_seg' => $fc->penal_cinta_seg,
            ])->values(),
        ];
    }

    private function formatVuelta($v, FechaCategoria $fc): array
    {
        $tramos = $v->tramos->map(function ($tramo) use ($fc) {
            return [
                'id' => $tramo->id,
                'letra' => $tramo->letra,
                'tiempo_ms' => $tramo->tiempo_ms,
                'estacas' => $tramo->estacas,
                'cintas' => $tramo->cintas,
                'estiradas' => $tramo->estiradas,
                'tiempos_muertos' => $tramo->tiemposMuertos->map(fn($tm) => [
                    'id' => $tm->id,
                    'segundos' => $tm->segundos,
                    'comentario' => $tm->comentario,
                ]),
                'total_trancas_seg' => $tramo->totalTrancasSeg(),
                'tiempo_con_penal' => $tramo->tiempoConPenal($fc->penal_estaca_seg, $fc->penal_cinta_seg, $fc->penal_estirada_seg ?? 60),
            ];
        });

        return [
            'id' => $v->id,
            'numero_vuelta' => $v->numero_vuelta,
            'fase' => $v->fase,
            'nula' => $v->nula,
            'confirmada' => $v->confirmada,
            'largo' => (bool) $v->largo,
            'tramos' => $tramos,
            'total_vuelta' => $v->calcularTotal($fc->penal_estaca_seg, $fc->penal_cinta_seg, $fc->penal_estirada_seg ?? 60),
        ];
    }
}
