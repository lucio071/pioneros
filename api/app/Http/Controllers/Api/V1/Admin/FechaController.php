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
}
