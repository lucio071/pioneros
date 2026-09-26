<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Fecha;
use App\Models\FechaCategoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FechaController extends Controller
{
    public function index(): JsonResponse
    {
        $fechas = Fecha::with('fechaCategorias.categoriaCatalogo')
            ->withCount('tripulaciones')
            ->orderByDesc('fecha')
            ->get();

        return response()->json(['data' => $fechas]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'fecha' => 'required|date',
            'tipo_pista' => 'required|in:simple,doble',
            'vueltas_clasificacion' => 'required|integer|min:1|max:5',
            'tiene_final' => 'boolean',
            'finalistas_top' => 'nullable|integer|min:2',
            'trazado_simple' => 'nullable|in:mismo_lugar,lugares_distintos',
            'campeonato_id' => 'nullable|uuid|exists:campeonatos,id',
        ]);

        // Force tipo_pista if campeonato has tipo_pista_forzado
        if (!empty($data['campeonato_id'])) {
            $campeonato = \App\Models\Campeonato::find($data['campeonato_id']);
            if ($campeonato && $campeonato->tipo_pista_forzado) {
                $data['tipo_pista'] = $campeonato->tipo_pista_forzado;
            }
        }

        $fecha = Fecha::create($data);

        return response()->json(['data' => $fecha->load('fechaCategorias.categoriaCatalogo')], 201);
    }

    public function update(Request $request, Fecha $fecha): JsonResponse
    {
        if ($fecha->estado === 'finalizada') {
            return response()->json(['message' => 'Fecha finalizada, no se puede editar'], 422);
        }

        $data = $request->validate([
            'nombre' => 'sometimes|string|max:255',
            'fecha' => 'sometimes|date',
            'tipo_pista' => 'sometimes|in:simple,doble',
            'vueltas_clasificacion' => 'sometimes|integer|min:1|max:5',
            'tiene_final' => 'sometimes|boolean',
            'finalistas_top' => 'nullable|integer|min:2',
            'trazado_simple' => 'nullable|in:mismo_lugar,lugares_distintos',
        ]);

        $fecha->update($data);
        $fecha->incrementVersion();

        return response()->json(['data' => $fecha->load('fechaCategorias.categoriaCatalogo')]);
    }

    public function destroy(Request $request, Fecha $fecha): JsonResponse
    {
        // Solo admin puede eliminar
        if ($request->user()->rol !== 'admin') {
            return response()->json(['message' => 'Solo el administrador puede eliminar fechas'], 403);
        }

        // Si estaba activa, resetear estados de cronometraje
        if (in_array($fecha->estado, ['activa', 'en_curso'])) {
            foreach (\App\Models\EstadoCronometraje::all() as $e) {
                $e->resetear(null);
            }
        }

        // Cascada: FK con cascadeOnDelete se encarga de todo
        $fecha->delete();

        return response()->json(null, 204);
    }

    public function addCategoria(Request $request, Fecha $fecha): JsonResponse
    {
        $data = $request->validate([
            'categoria_catalogo_id' => 'required|uuid|exists:categorias_catalogo,id',
            'penal_estaca_seg' => 'required|integer|min:0',
            'penal_cinta_seg' => 'required|integer|min:0',
            'penal_estirada_seg' => 'integer|min:0',
            'tipo_pista' => 'nullable|in:simple,doble',
        ]);

        $data['fecha_id'] = $fecha->id;
        $fc = FechaCategoria::create($data);
        $fecha->incrementVersion();

        return response()->json(['data' => $fc->load('categoriaCatalogo')], 201);
    }

    public function updateCategoria(Request $request, FechaCategoria $fechaCategoria): JsonResponse
    {
        $data = $request->validate([
            'penal_estaca_seg' => 'sometimes|integer|min:0',
            'penal_cinta_seg' => 'sometimes|integer|min:0',
            'penal_estirada_seg' => 'sometimes|integer|min:0',
            'tipo_pista' => 'nullable|in:simple,doble',
        ]);

        $fechaCategoria->update($data);
        $fechaCategoria->fecha->incrementVersion();

        return response()->json(['data' => $fechaCategoria->load('categoriaCatalogo')]);
    }

    public function removeCategoria(FechaCategoria $fechaCategoria): JsonResponse
    {
        if ($fechaCategoria->tripulaciones()->exists()) {
            return response()->json(['message' => 'No se puede eliminar: tiene tripulaciones'], 409);
        }

        $fecha = $fechaCategoria->fecha;
        $fechaCategoria->delete();
        $fecha->incrementVersion();

        return response()->json(null, 204);
    }

    public function configurar(Fecha $fecha): JsonResponse
    {
        if ($fecha->estado !== 'borrador') {
            return response()->json(['message' => 'Solo fechas en borrador se pueden configurar'], 422);
        }

        $catConfiguradas = $fecha->fechaCategorias()
            ->whereNotNull('penal_estaca_seg')
            ->whereNotNull('penal_cinta_seg')
            ->count();

        if ($catConfiguradas === 0) {
            return response()->json(['message' => 'Necesita al menos una categoria con penalizaciones'], 422);
        }

        if ($fecha->tiene_final && !$fecha->finalistas_top) {
            return response()->json(['message' => 'Falta definir cantidad de finalistas'], 422);
        }

        $fecha->update(['estado' => 'configurada']);
        $fecha->incrementVersion();

        return response()->json(['data' => $fecha->load('fechaCategorias.categoriaCatalogo')]);
    }

    public function activar(Fecha $fecha): JsonResponse
    {
        if (!in_array($fecha->estado, ['configurada', 'borrador'])) {
            return response()->json(['message' => 'Solo fechas configuradas se pueden activar'], 422);
        }

        Fecha::where('estado', 'activa')->update(['estado' => 'configurada']);
        $fecha->update(['estado' => 'activa']);
        $fecha->incrementVersion();

        return response()->json(['data' => $fecha->load('fechaCategorias.categoriaCatalogo')]);
    }

    public function finalizar(Fecha $fecha): JsonResponse
    {
        if ($fecha->estado !== 'activa') {
            return response()->json(['message' => 'Solo fechas activas se pueden finalizar'], 422);
        }

        $fecha->update(['estado' => 'finalizada']);
        $fecha->incrementVersion();

        return response()->json(['data' => $fecha->load('fechaCategorias.categoriaCatalogo')]);
    }

    public function desbloquear(Request $request, Fecha $fecha): JsonResponse
    {
        // Solo admin puede desbloquear
        if (($request->user()->rol ?? 'cronometrista') !== 'admin') {
            return response()->json(['message' => 'Solo el administrador puede desbloquear fechas'], 403);
        }

        if ($fecha->estado !== 'finalizada') {
            return response()->json(['message' => 'Solo fechas finalizadas se pueden desbloquear'], 422);
        }

        $fecha->update(['estado' => 'activa']);
        $fecha->incrementVersion();

        return response()->json(['data' => $fecha->load('fechaCategorias.categoriaCatalogo')]);
    }

    /**
     * Export fecha results as CSV.
     */
    public function exportCsv(Fecha $fecha)
    {
        $filename = 'resultados_' . \Str::slug($fecha->nombre) . '_' . $fecha->fecha . '.csv';

        $callback = function () use ($fecha) {
            $handle = fopen('php://output', 'w');
            // BOM for Excel UTF-8
            fwrite($handle, "\xEF\xBB\xBF");

            // Header
            fputcsv($handle, ['RESULTADOS: ' . $fecha->nombre . ' - ' . $fecha->fecha], ';');
            fputcsv($handle, [], ';');

            foreach ($fecha->fechaCategorias()->with('categoriaCatalogo')->get() as $fc) {
                $catNombre = $fc->categoriaCatalogo->nombre ?? '';
                $pe = $fc->penal_estaca_seg;
                $pc = $fc->penal_cinta_seg;
                $ps = $fc->penal_estirada_seg ?? 60;

                fputcsv($handle, ['CATEGORIA: ' . $catNombre], ';');
                fputcsv($handle, ['Penalizaciones: Estaca=' . $pe . 's, Cinta=' . $pc . 's, Estirada=' . $ps . 's'], ';');
                fputcsv($handle, [], ';');

                // Column headers
                fputcsv($handle, [
                    'Pos', 'Numero', 'Nombre', 'Piloto', 'Copiloto', 'Estado',
                    'Mejor Vuelta', 'Mejor V#',
                    'V1 Total', 'V1 Tramo A', 'V1 Tramo B', 'V1 Estacas', 'V1 Cintas', 'V1 Estiradas', 'V1 TM(s)',
                    'V2 Total', 'V2 Tramo A', 'V2 Tramo B', 'V2 Estacas', 'V2 Cintas', 'V2 Estiradas', 'V2 TM(s)',
                    'V3 Total', 'V3 Tramo A', 'V3 Tramo B', 'V3 Estacas', 'V3 Cintas', 'V3 Estiradas', 'V3 TM(s)',
                    'Puntos',
                ], ';');

                $trips = $fc->tripulaciones()
                    ->with('vueltas.tramos.tiemposMuertos')
                    ->orderBy('orden_largada')
                    ->get();

                // Sort by mejor vuelta
                $sorted = $trips->sortBy(function ($t) use ($fc) {
                    $ms = $t->mejorVueltaMs($fc);
                    return $ms ?? PHP_INT_MAX;
                })->values();

                $pos = 0;
                foreach ($sorted as $t) {
                    $mejorMs = $t->mejorVueltaMs($fc);
                    $mejorNum = $t->mejorVueltaNumero($fc);
                    $isDnf = $t->estado === 'abandonado' || $mejorMs === null;

                    if (!$isDnf) $pos++;

                    $row = [
                        $isDnf ? 'DNF' : $pos,
                        $t->numero,
                        $t->nombre,
                        $t->piloto,
                        $t->copiloto ?? '',
                        $t->estado,
                        $mejorMs !== null ? $this->formatMs($mejorMs) : '',
                        $mejorNum ?? '',
                    ];

                    // V1, V2, V3
                    for ($vn = 1; $vn <= 3; $vn++) {
                        $v = $t->vueltas->where('numero_vuelta', $vn)->where('fase', 'clasificacion')->first();
                        if ($v && !$v->nula) {
                            $total = $v->calcularTotal($pe, $pc, $ps);
                            $tramoA = $v->tramos->where('letra', 'A')->first();
                            $tramoB = $v->tramos->where('letra', 'B')->first();
                            $estacas = ($tramoA->estacas ?? 0) + ($tramoB->estacas ?? 0);
                            $cintas = ($tramoA->cintas ?? 0) + ($tramoB->cintas ?? 0);
                            $estiradas = ($tramoA->estiradas ?? 0) + ($tramoB->estiradas ?? 0);
                            $tmA = $tramoA ? $tramoA->totalTrancasSeg() : 0;
                            $tmB = $tramoB ? $tramoB->totalTrancasSeg() : 0;

                            $row[] = $total !== null ? $this->formatMs($total) : '';
                            $row[] = $tramoA ? $this->formatMs($tramoA->tiempo_ms) : '';
                            $row[] = $tramoB ? $this->formatMs($tramoB->tiempo_ms) : '';
                            $row[] = $estacas;
                            $row[] = $cintas;
                            $row[] = $estiradas;
                            $row[] = $tmA + $tmB;
                        } elseif ($v && $v->nula) {
                            $row = array_merge($row, ['NULA', '', '', '', '', '', '']);
                        } else {
                            $row = array_merge($row, ['', '', '', '', '', '', '']);
                        }
                    }

                    $row[] = $t->puntos ?? 0;
                    fputcsv($handle, $row, ';');
                }

                fputcsv($handle, [], ';');
            }

            fclose($handle);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function formatMs(?int $ms): string
    {
        if ($ms === null) return '';
        $totalSec = floor($ms / 1000);
        $cents = floor(($ms % 1000) / 10);
        $min = floor($totalSec / 60);
        $sec = $totalSec % 60;
        return sprintf('%02d:%02d.%02d', $min, $sec, $cents);
    }
}
