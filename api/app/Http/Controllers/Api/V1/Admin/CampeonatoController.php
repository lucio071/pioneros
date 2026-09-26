<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campeonato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampeonatoController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Campeonato::withCount('fechas')->orderByDesc('anio')->orderBy('nombre')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:255',
            'anio' => 'required|integer|min:2020|max:2100',
            'tipo_pista_forzado' => 'nullable|in:simple,doble',
        ]);

        return response()->json(['data' => Campeonato::create($data)], 201);
    }

    public function update(Request $request, Campeonato $campeonato): JsonResponse
    {
        if ($campeonato->estado === 'finalizado') {
            return response()->json(['message' => 'Campeonato finalizado'], 422);
        }

        $data = $request->validate([
            'nombre' => 'sometimes|string|max:255',
            'anio' => 'sometimes|integer|min:2020|max:2100',
            'tipo_pista_forzado' => 'nullable|in:simple,doble',
        ]);

        $campeonato->update($data);

        return response()->json(['data' => $campeonato]);
    }

    public function destroy(Campeonato $campeonato): JsonResponse
    {
        if ($campeonato->fechas()->exists()) {
            return response()->json(['message' => 'No se puede eliminar: tiene fechas'], 409);
        }

        $campeonato->delete();

        return response()->json(null, 204);
    }

    public function activar(Campeonato $campeonato): JsonResponse
    {
        Campeonato::where('estado', 'activo')->update(['estado' => 'borrador']);
        $campeonato->update(['estado' => 'activo']);

        return response()->json(['data' => $campeonato]);
    }

    public function finalizar(Campeonato $campeonato): JsonResponse
    {
        if ($campeonato->estado !== 'activo') {
            return response()->json(['message' => 'Solo activos se pueden finalizar'], 422);
        }

        $campeonato->update(['estado' => 'finalizado']);

        return response()->json(['data' => $campeonato]);
    }

    /**
     * Export campeonato general standings as CSV.
     */
    public function exportCsv(Campeonato $campeonato)
    {
        $filename = 'campeonato_' . \Str::slug($campeonato->nombre) . '_' . $campeonato->anio . '.csv';

        $callback = function () use ($campeonato) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['CAMPEONATO: ' . $campeonato->nombre . ' ' . $campeonato->anio], ';');
            fputcsv($handle, [], ';');

            $fechas = $campeonato->fechas()->with('fechaCategorias.categoriaCatalogo', 'fechaCategorias.tripulaciones')->get();

            // Collect all categories
            $categorias = $fechas->flatMap(fn($f) => $f->fechaCategorias)->groupBy(fn($fc) => $fc->categoriaCatalogo->nombre ?? $fc->id);

            foreach ($categorias as $catNombre => $fcs) {
                fputcsv($handle, ['CATEGORIA: ' . $catNombre], ';');

                // Header: Pos, Numero, Nombre, Piloto, [fecha1, fecha2, ...], Total
                $headerRow = ['Pos', 'Numero', 'Nombre', 'Piloto'];
                foreach ($fechas as $f) {
                    $headerRow[] = $f->nombre;
                }
                $headerRow[] = 'TOTAL';
                fputcsv($handle, $headerRow, ';');

                // Collect pilotos across all fechas
                $pilotos = [];
                foreach ($fcs as $fc) {
                    foreach ($fc->tripulaciones as $t) {
                        $key = $t->numero;
                        if (!isset($pilotos[$key])) {
                            $pilotos[$key] = [
                                'numero' => $t->numero,
                                'nombre' => $t->nombre,
                                'piloto' => $t->piloto,
                                'puntos_por_fecha' => [],
                                'total' => 0,
                            ];
                        }
                        $fechaNombre = $fc->fecha->nombre;
                        $pts = $t->puntos ?? 0;
                        $pilotos[$key]['puntos_por_fecha'][$fechaNombre] = $pts;
                        $pilotos[$key]['total'] += $pts;
                    }
                }

                // Sort by total desc
                uasort($pilotos, fn($a, $b) => $b['total'] - $a['total']);

                $pos = 0;
                foreach ($pilotos as $p) {
                    $pos++;
                    $row = [$pos, $p['numero'], $p['nombre'], $p['piloto']];
                    foreach ($fechas as $f) {
                        $row[] = $p['puntos_por_fecha'][$f->nombre] ?? '';
                    }
                    $row[] = $p['total'];
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
}
