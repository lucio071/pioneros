<?php

namespace Tests\Feature;

use App\Models\CategoriaCatalogo;
use App\Models\Fecha;
use App\Models\FechaCategoria;
use App\Models\Tripulacion;
use App\Models\User;
use App\Models\Vuelta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CarreraSimulacionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Fecha $fecha;
    private FechaCategoria $fc;
    private array $trips; // [#301, #302, #303, #304]

    protected function setUp(): void
    {
        parent::setUp();

        // Admin user
        $this->admin = User::create([
            'name' => 'Admin Test',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'rol' => 'admin',
        ]);

        // Categoria catalogo
        $cat = CategoriaCatalogo::create(['nombre' => 'T3', 'slug' => 't3']);

        // Fecha doble, 3 vueltas
        $this->fecha = Fecha::create([
            'nombre' => 'Test Carrera',
            'fecha' => now()->toDateString(),
            'tipo_pista' => 'doble',
            'vueltas_clasificacion' => 3,
            'tiene_final' => false,
            'finalistas_top' => 3,
            'estado' => 'activa',
        ]);

        // Categoria con penalizaciones
        $this->fc = FechaCategoria::create([
            'fecha_id' => $this->fecha->id,
            'categoria_catalogo_id' => $cat->id,
            'penal_estaca_seg' => 5,
            'penal_cinta_seg' => 10,
            'penal_estirada_seg' => 60,
        ]);

        // 4 tripulaciones
        $this->trips = [];
        for ($i = 1; $i <= 4; $i++) {
            $this->trips[] = Tripulacion::create([
                'fecha_categoria_id' => $this->fc->id,
                'numero' => '30' . $i,
                'nombre' => "Equipo $i",
                'piloto' => "Piloto $i",
                'copiloto' => "Copiloto $i",
                'estado' => 'en_pista',
            ]);
        }
    }

    /**
     * Helper: guardar tramo via API
     */
    private function guardarTramo(Tripulacion $trip, int $vuelta, string $letra, int $tiempoMs, int $estacas = 0, int $cintas = 0, int $estiradas = 0, array $tiemposMuertos = []): \Illuminate\Testing\TestResponse
    {
        $body = [
            'tiempo_ms' => $tiempoMs,
            'estacas' => $estacas,
            'cintas' => $cintas,
            'estiradas' => $estiradas,
        ];
        if (!empty($tiemposMuertos)) {
            $body['tiempos_muertos'] = $tiemposMuertos;
        }

        return $this->actingAs($this->admin)
            ->putJson("/api/v1/tripulaciones/{$trip->id}/vueltas/{$vuelta}/tramos/{$letra}", $body);
    }

    /**
     * Helper: obtener ranking
     */
    private function getRanking(): array
    {
        $response = $this->getJson("/api/v1/public/fechas/{$this->fecha->id}/categorias/{$this->fc->id}/ranking");
        $response->assertOk();
        return $response->json('data');
    }

    /**
     * 1. Vuelta normal doble: corrida 1 en A, corrida 2 en B
     */
    public function test_vuelta_doble_suma_corrida1_y_corrida2(): void
    {
        $trip = $this->trips[0]; // #301

        // Corrida 1: pista A → tramo A = 1:23.450 (83450ms)
        $this->guardarTramo($trip, 1, 'A', 83450)->assertOk();

        // Corrida 2: pista B → tramo B = 1:45.230 (105230ms)
        $this->guardarTramo($trip, 1, 'B', 105230)->assertOk();

        // Total = 83450 + 105230 = 188680ms = 3:08.680
        $vuelta = $trip->fresh()->vueltas()->where('numero_vuelta', 1)->with('tramos.tiemposMuertos')->first();
        $total = $vuelta->calcularTotal($this->fc->penal_estaca_seg, $this->fc->penal_cinta_seg, $this->fc->penal_estirada_seg);

        $this->assertEquals(188680, $total, 'Vuelta doble debe ser suma exacta de tramo A + tramo B');
    }

    /**
     * 2. Penalizaciones que suman: estacas + cintas + estiradas
     */
    public function test_penalizaciones_suman_al_tiempo(): void
    {
        $trip = $this->trips[0];
        $pe = $this->fc->penal_estaca_seg;  // 5
        $pc = $this->fc->penal_cinta_seg;   // 10
        $ps = $this->fc->penal_estirada_seg; // 60

        // Tramo A: 60000ms + 2 estacas + 1 cinta + 1 estirada
        $this->guardarTramo($trip, 1, 'A', 60000, 2, 1, 1)->assertOk();
        // Tramo B: 60000ms sin penalizaciones
        $this->guardarTramo($trip, 1, 'B', 60000)->assertOk();

        $vuelta = $trip->fresh()->vueltas()->where('numero_vuelta', 1)->with('tramos.tiemposMuertos')->first();
        $total = $vuelta->calcularTotal($pe, $pc, $ps);

        // 60000 + (2*5 + 1*10 + 1*60)*1000 + 60000 = 60000 + 80000 + 60000 = 200000
        $esperado = 60000 + (2 * $pe + 1 * $pc + 1 * $ps) * 1000 + 60000;
        $this->assertEquals($esperado, $total, "Penalizaciones deben sumar: 2E*{$pe}s + 1C*{$pc}s + 1S*{$ps}s");
    }

    /**
     * 3. Combinado estaca + tiempo muerto: base 02:08.48 + 1 estaca(+5s) - 30s TM
     *    Resultado: 128480 + 5000 - 30000 = 103480ms = 01:43.48
     */
    public function test_estaca_suma_y_tiempo_muerto_resta(): void
    {
        $trip = $this->trips[0];

        // Tramo A: 02:08.480 = 128480ms, 1 estaca, 1 TM de 30s
        $this->guardarTramo($trip, 1, 'A', 128480, 1, 0, 0, [30])->assertOk();
        // Tramo B: sin tiempo (pista simple conceptual, pero para calcular necesitamos B)
        $this->guardarTramo($trip, 1, 'B', 0)->assertOk();

        $vuelta = $trip->fresh()->vueltas()->where('numero_vuelta', 1)->with('tramos.tiemposMuertos')->first();
        $tramoA = $vuelta->tramos->where('letra', 'A')->first();
        $totalTramoA = $tramoA->tiempoConPenal($this->fc->penal_estaca_seg, $this->fc->penal_cinta_seg, $this->fc->penal_estirada_seg);

        // 128480 + 5000 (1 estaca * 5s) - 30000 (TM 30s) = 103480
        $this->assertEquals(103480, $totalTramoA, 'Estaca debe sumar 5s y TM debe restar 30s');
    }

    /**
     * 4. Abandona una corrida: vuelta NULA, compañero corre solo
     */
    public function test_abandono_vuelta_nula_companero_sigue(): void
    {
        $tripA = $this->trips[0]; // #301
        $tripB = $this->trips[1]; // #302

        // Corrida 1: ambos corren
        $this->guardarTramo($tripA, 1, 'A', 80000)->assertOk();
        $this->guardarTramo($tripB, 1, 'B', 85000)->assertOk();

        // #301 abandona: crear vuelta vacía y marcar nula
        $this->guardarTramo($tripA, 1, 'A', 0)->assertOk(); // ya existe, actualiza
        $vueltaA = $tripA->fresh()->vueltas()->where('numero_vuelta', 1)->first();
        $this->actingAs($this->admin)->postJson("/api/v1/vueltas/{$vueltaA->id}/nula")->assertOk();

        // Vuelta de #301 es NULA
        $vueltaA->refresh();
        $this->assertTrue($vueltaA->nula, '#301 vuelta debe ser NULA después de abandono');

        // #301 NO es DNF (puede correr V2)
        $tripA->refresh();
        $this->assertNotEquals('abandonado', $tripA->estado, '#301 no debe estar abandonado, solo vuelta nula');

        // #302 corre corrida 2 solo (tramo A) sin error
        $response = $this->guardarTramo($tripB, 1, 'A', 90000);
        $response->assertOk();

        // #302 tiene vuelta completa (A + B)
        $vueltaB = $tripB->fresh()->vueltas()->where('numero_vuelta', 1)->with('tramos')->first();
        $letras = $vueltaB->tramos->pluck('letra')->toArray();
        $this->assertContains('A', $letras, '#302 debe tener tramo A');
        $this->assertContains('B', $letras, '#302 debe tener tramo B');
    }

    /**
     * 5. DNF solo se permite marcando como abandonado (no por V1)
     */
    public function test_dnf_marca_abandonado(): void
    {
        $trip = $this->trips[0];

        // Marcar abandonado
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tripulaciones/{$trip->id}/abandonar");
        $response->assertOk();

        $trip->refresh();
        $this->assertEquals('abandonado', $trip->estado, 'DNF debe marcar estado como abandonado');
    }

    /**
     * 6. Reincorporar tras DNF
     */
    public function test_reincorporar_tras_dnf(): void
    {
        $trip = $this->trips[0];

        // Abandonar
        $this->actingAs($this->admin)
            ->postJson("/api/v1/tripulaciones/{$trip->id}/abandonar")
            ->assertOk();

        $trip->refresh();
        $this->assertEquals('abandonado', $trip->estado);

        // Reincorporar
        $response = $this->actingAs($this->admin)
            ->postJson("/api/v1/tripulaciones/{$trip->id}/reincorporar");
        $response->assertOk();

        $trip->refresh();
        $this->assertNotEquals('abandonado', $trip->estado, 'Después de reincorporar no debe estar abandonado');
    }

    /**
     * 7. Par armado: guardar tramos para un par específico
     */
    public function test_par_armado_guarda_correctamente(): void
    {
        $tripA = $this->trips[0]; // #301
        $tripB = $this->trips[1]; // #302

        // Corrida 1
        $this->guardarTramo($tripA, 1, 'A', 70000)->assertOk();
        $this->guardarTramo($tripB, 1, 'B', 75000)->assertOk();

        // Corrida 2 (invertidos)
        $this->guardarTramo($tripB, 1, 'A', 72000)->assertOk();
        $this->guardarTramo($tripA, 1, 'B', 78000)->assertOk();

        // #301: tramo A=70000, tramo B=78000
        $v301 = $tripA->fresh()->vueltas()->where('numero_vuelta', 1)->with('tramos')->first();
        $this->assertEquals(70000, $v301->tramos->where('letra', 'A')->first()->tiempo_ms);
        $this->assertEquals(78000, $v301->tramos->where('letra', 'B')->first()->tiempo_ms);

        // #302: tramo A=72000, tramo B=75000
        $v302 = $tripB->fresh()->vueltas()->where('numero_vuelta', 1)->with('tramos')->first();
        $this->assertEquals(72000, $v302->tramos->where('letra', 'A')->first()->tiempo_ms);
        $this->assertEquals(75000, $v302->tramos->where('letra', 'B')->first()->tiempo_ms);
    }

    /**
     * 8. Ranking: clasificado con >=1 vuelta válida, NO es DNF
     *    Bug anterior: con fecha finalizada, auto con V1 válida y V2/V3 sin correr quedaba DNF
     */
    public function test_ranking_clasificado_con_una_vuelta_valida(): void
    {
        $trip = $this->trips[0]; // #301

        // Solo V1 completa
        $this->guardarTramo($trip, 1, 'A', 80000)->assertOk();
        $this->guardarTramo($trip, 1, 'B', 85000)->assertOk();

        // Finalizar fecha
        $this->actingAs($this->admin)
            ->postJson("/api/v1/fechas/{$this->fecha->id}/finalizar")
            ->assertOk();

        // Ranking: #301 debe estar clasificado, NO en DNF
        $ranking = $this->getRanking();
        $ranked = collect($ranking['ranking'] ?? []);
        $dnf = collect($ranking['dnf'] ?? []);

        $this->assertTrue($ranked->contains('numero', '301'), '#301 con V1 válida debe estar en ranking');
        $this->assertFalse($dnf->contains('numero', '301'), '#301 con V1 válida NO debe ser DNF');
    }

    /**
     * 9. DNF real: abandonado sin vueltas válidas
     */
    public function test_ranking_dnf_sin_vueltas_validas(): void
    {
        $trip = $this->trips[0]; // #301
        $trip2 = $this->trips[1]; // #302

        // #301 abandonado
        $this->actingAs($this->admin)
            ->postJson("/api/v1/tripulaciones/{$trip->id}/abandonar")
            ->assertOk();

        // #302 con V1 completa (para que haya ranking)
        $this->guardarTramo($trip2, 1, 'A', 80000)->assertOk();
        $this->guardarTramo($trip2, 1, 'B', 85000)->assertOk();

        // Finalizar
        $this->actingAs($this->admin)
            ->postJson("/api/v1/fechas/{$this->fecha->id}/finalizar")
            ->assertOk();

        $ranking = $this->getRanking();
        $dnf = collect($ranking['dnf'] ?? []);

        $this->assertTrue($dnf->contains('numero', '301'), '#301 abandonado debe ser DNF');
    }

    /**
     * 10. Multi-vuelta: ranking toma la mejor vuelta válida
     */
    public function test_ranking_toma_mejor_vuelta(): void
    {
        $trip = $this->trips[0]; // #301

        // V1: 80000 + 85000 = 165000
        $this->guardarTramo($trip, 1, 'A', 80000)->assertOk();
        $this->guardarTramo($trip, 1, 'B', 85000)->assertOk();

        // V2: 70000 + 72000 = 142000 (mejor)
        $this->guardarTramo($trip, 2, 'A', 70000)->assertOk();
        $this->guardarTramo($trip, 2, 'B', 72000)->assertOk();

        // V3: 90000 + 95000 = 185000 (peor)
        $this->guardarTramo($trip, 3, 'A', 90000)->assertOk();
        $this->guardarTramo($trip, 3, 'B', 95000)->assertOk();

        $ranking = $this->getRanking();
        $ranked = collect($ranking['ranking'] ?? []);
        $trip301 = $ranked->firstWhere('numero', '301');

        $this->assertNotNull($trip301, '#301 debe estar en ranking');
        $this->assertEquals(142000, $trip301['mejor_vuelta_ms'], 'Ranking debe usar la mejor vuelta (V2 = 142000)');
        $this->assertEquals(2, $trip301['mejor_vuelta_numero'], 'Mejor vuelta debe ser V2');
    }

    /**
     * 11. Estiradas: penalización de 60s por estirada
     */
    public function test_estirada_suma_60_segundos(): void
    {
        $trip = $this->trips[0];

        // Tramo A: 60000ms + 1 estirada (60s = 60000ms)
        $this->guardarTramo($trip, 1, 'A', 60000, 0, 0, 1)->assertOk();
        $this->guardarTramo($trip, 1, 'B', 60000)->assertOk();

        $vuelta = $trip->fresh()->vueltas()->where('numero_vuelta', 1)->with('tramos.tiemposMuertos')->first();
        $total = $vuelta->calcularTotal($this->fc->penal_estaca_seg, $this->fc->penal_cinta_seg, $this->fc->penal_estirada_seg);

        // 60000 + 60000(estirada) + 60000 = 180000
        $this->assertEquals(180000, $total, 'Estirada debe sumar 60s (60000ms)');
    }

    /**
     * 12. Pista doble: vuelta completa (A+B) vs incompleta (solo A)
     */
    public function test_vuelta_completa_vs_incompleta_doble(): void
    {
        $trip = $this->trips[0];

        // V1 solo tramo A
        $this->guardarTramo($trip, 1, 'A', 80000)->assertOk();

        // calcularTotal retorna el tiempo del tramo que existe
        $vuelta = $trip->fresh()->vueltas()->where('numero_vuelta', 1)->with('tramos.tiemposMuertos')->first();
        $total = $vuelta->calcularTotal($this->fc->penal_estaca_seg, $this->fc->penal_cinta_seg, $this->fc->penal_estirada_seg);
        $this->assertEquals(80000, $total, 'calcularTotal con solo tramo A retorna ese tramo');

        // Agregar tramo B → ahora completa
        $this->guardarTramo($trip, 1, 'B', 90000)->assertOk();
        $vuelta->refresh()->load('tramos.tiemposMuertos');
        $totalCompleta = $vuelta->calcularTotal($this->fc->penal_estaca_seg, $this->fc->penal_cinta_seg, $this->fc->penal_estirada_seg);
        $this->assertEquals(170000, $totalCompleta, 'Vuelta completa (A+B) = 80000 + 90000');

        // mejorVueltaMs filtra vueltas incompletas en doble
        $trip->refresh();
        // Agregar V2 con solo tramo A (incompleta)
        $this->guardarTramo($trip, 2, 'A', 50000)->assertOk();
        $trip->refresh();
        $mejorMs = $trip->mejorVueltaMs($this->fc);
        // Debe tomar V1 (170000) no V2 (50000 incompleta)
        $this->assertEquals(170000, $mejorMs, 'mejorVueltaMs debe ignorar vueltas incompletas en doble');
    }

    /**
     * 13. Posición general: ranking ordena por mejor vuelta
     */
    public function test_ranking_posiciones_correctas(): void
    {
        // #301: vuelta = 150000 (rápido)
        $this->guardarTramo($this->trips[0], 1, 'A', 70000)->assertOk();
        $this->guardarTramo($this->trips[0], 1, 'B', 80000)->assertOk();

        // #302: vuelta = 200000 (lento)
        $this->guardarTramo($this->trips[1], 1, 'A', 100000)->assertOk();
        $this->guardarTramo($this->trips[1], 1, 'B', 100000)->assertOk();

        // #303: vuelta = 140000 (más rápido)
        $this->guardarTramo($this->trips[2], 1, 'A', 65000)->assertOk();
        $this->guardarTramo($this->trips[2], 1, 'B', 75000)->assertOk();

        $ranking = $this->getRanking();
        $ranked = collect($ranking['ranking'] ?? []);

        $this->assertEquals('303', $ranked[0]['numero'], 'Posición 1 debe ser #303 (140000)');
        $this->assertEquals('301', $ranked[1]['numero'], 'Posición 2 debe ser #301 (150000)');
        $this->assertEquals('302', $ranked[2]['numero'], 'Posición 3 debe ser #302 (200000)');

        $this->assertEquals(1, $ranked[0]['posicion']);
        $this->assertEquals(2, $ranked[1]['posicion']);
        $this->assertEquals(3, $ranked[2]['posicion']);

        // Diferencia del segundo = su tiempo - tiempo del primero
        $this->assertEquals(10000, $ranked[1]['diferencia'], 'Diferencia de #301 = 150000-140000');
    }

    /**
     * 14. Carrera completa: 2 pares, V1 + V2, ranking final
     */
    public function test_carrera_completa_dos_pares(): void
    {
        // Par 1: #301 vs #302 — V1
        $this->guardarTramo($this->trips[0], 1, 'A', 80000)->assertOk(); // #301 corrida 1
        $this->guardarTramo($this->trips[1], 1, 'B', 85000)->assertOk(); // #302 corrida 1
        $this->guardarTramo($this->trips[1], 1, 'A', 82000)->assertOk(); // #302 corrida 2
        $this->guardarTramo($this->trips[0], 1, 'B', 88000)->assertOk(); // #301 corrida 2

        // Par 2: #303 vs #304 — V1
        $this->guardarTramo($this->trips[2], 1, 'A', 75000)->assertOk();
        $this->guardarTramo($this->trips[3], 1, 'B', 90000)->assertOk();
        $this->guardarTramo($this->trips[3], 1, 'A', 87000)->assertOk();
        $this->guardarTramo($this->trips[2], 1, 'B', 78000)->assertOk();

        // V2: Par 1
        $this->guardarTramo($this->trips[0], 2, 'A', 70000)->assertOk();
        $this->guardarTramo($this->trips[1], 2, 'B', 72000)->assertOk();
        $this->guardarTramo($this->trips[1], 2, 'A', 71000)->assertOk();
        $this->guardarTramo($this->trips[0], 2, 'B', 75000)->assertOk();

        $ranking = $this->getRanking();
        $ranked = collect($ranking['ranking'] ?? []);

        // 4 autos clasificados
        $this->assertCount(4, $ranked, 'Deben estar los 4 clasificados');

        // #301: V1=168000, V2=145000 → mejor=145000
        $trip301 = $ranked->firstWhere('numero', '301');
        $this->assertEquals(145000, $trip301['mejor_vuelta_ms'], '#301 mejor vuelta = V2 (145000)');

        // #303: V1=153000 (unica) → mejor=153000
        $trip303 = $ranked->firstWhere('numero', '303');
        $this->assertEquals(153000, $trip303['mejor_vuelta_ms'], '#303 mejor vuelta = V1 (153000)');
    }

    /**
     * 15. Vuelta nula + vuelta válida: trip tiene V1 nula y V2 válida, clasifica por V2
     */
    public function test_vuelta_nula_mas_valida_clasifica(): void
    {
        $trip = $this->trips[0];

        // V1: crear y anular
        $this->guardarTramo($trip, 1, 'A', 0)->assertOk();
        $vuelta1 = $trip->fresh()->vueltas()->where('numero_vuelta', 1)->first();
        $this->actingAs($this->admin)->postJson("/api/v1/vueltas/{$vuelta1->id}/nula")->assertOk();

        // V2: completa
        $this->guardarTramo($trip, 2, 'A', 70000)->assertOk();
        $this->guardarTramo($trip, 2, 'B', 80000)->assertOk();

        $trip->refresh();
        $mejorMs = $trip->mejorVueltaMs($this->fc);
        $this->assertEquals(150000, $mejorMs, 'Con V1 nula y V2 válida, mejor = V2 (150000)');
    }

    /**
     * 16. Confirmar vuelta: no se puede modificar después de confirmar
     */
    public function test_vuelta_confirmada_no_modificable(): void
    {
        $trip = $this->trips[0];

        $this->guardarTramo($trip, 1, 'A', 80000)->assertOk();
        $this->guardarTramo($trip, 1, 'B', 85000)->assertOk();

        // Confirmar
        $vuelta = $trip->fresh()->vueltas()->where('numero_vuelta', 1)->first();
        $this->actingAs($this->admin)->postJson("/api/v1/vueltas/{$vuelta->id}/confirmar")->assertOk();

        // Intentar modificar → debe rechazar
        $response = $this->guardarTramo($trip, 1, 'A', 70000);
        $response->assertStatus(422);
    }

    /**
     * 17. Tiempo muerto múltiple: 2 TM en el mismo tramo se restan ambos
     */
    public function test_multiples_tiempos_muertos(): void
    {
        $trip = $this->trips[0];

        // Tramo A: 120000ms + TM 15s + TM 25s = 120000 - 40000 = 80000
        $this->guardarTramo($trip, 1, 'A', 120000, 0, 0, 0, [15, 25])->assertOk();
        $this->guardarTramo($trip, 1, 'B', 60000)->assertOk();

        $vuelta = $trip->fresh()->vueltas()->where('numero_vuelta', 1)->with('tramos.tiemposMuertos')->first();
        $tramoA = $vuelta->tramos->where('letra', 'A')->first();

        $this->assertCount(2, $tramoA->tiemposMuertos, 'Deben haber 2 TM');
        $totalTM = $tramoA->totalTrancasSeg();
        $this->assertEquals(40, $totalTM, 'Total TM = 15 + 25 = 40s');

        $tiempoConPenal = $tramoA->tiempoConPenal($this->fc->penal_estaca_seg, $this->fc->penal_cinta_seg, $this->fc->penal_estirada_seg);
        $this->assertEquals(80000, $tiempoConPenal, '120000 - 40000(TM) = 80000');
    }

    /**
     * 18. Finalizar fecha: clasificados y DNF correctos
     */
    public function test_finalizar_fecha_ranking_correcto(): void
    {
        // #301: V1 completa
        $this->guardarTramo($this->trips[0], 1, 'A', 80000)->assertOk();
        $this->guardarTramo($this->trips[0], 1, 'B', 85000)->assertOk();

        // #302: V1 completa (más lento)
        $this->guardarTramo($this->trips[1], 1, 'A', 90000)->assertOk();
        $this->guardarTramo($this->trips[1], 1, 'B', 95000)->assertOk();

        // #303: abandonado
        $this->actingAs($this->admin)->postJson("/api/v1/tripulaciones/{$this->trips[2]->id}/abandonar")->assertOk();

        // #304: sin vueltas

        // Finalizar
        $this->actingAs($this->admin)->postJson("/api/v1/fechas/{$this->fecha->id}/finalizar")->assertOk();

        $ranking = $this->getRanking();
        $ranked = collect($ranking['ranking'] ?? []);
        $dnf = collect($ranking['dnf'] ?? []);

        // #301 y #302 clasificados
        $this->assertTrue($ranked->contains('numero', '301'));
        $this->assertTrue($ranked->contains('numero', '302'));
        $this->assertEquals(1, $ranked->firstWhere('numero', '301')['posicion']);
        $this->assertEquals(2, $ranked->firstWhere('numero', '302')['posicion']);

        // #303 DNF (abandonado)
        $this->assertTrue($dnf->contains('numero', '303'), '#303 abandonado debe ser DNF');

        // #304 DNF (sin vueltas, fecha finalizada)
        $this->assertTrue($dnf->contains('numero', '304'), '#304 sin vueltas debe ser DNF al finalizar');
    }
}
