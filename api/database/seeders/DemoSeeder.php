<?php

namespace Database\Seeders;

use App\Models\CategoriaCatalogo;
use App\Models\Fecha;
use App\Models\FechaCategoria;
use App\Models\Tripulacion;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Cronometrista
        User::updateOrCreate(
            ['email' => 'crono@pioneros4x4.com'],
            ['name' => 'Cronometrista', 'password' => Hash::make('Pioneros2024!')]
        );

        // Categorias catalogo
        $standard = CategoriaCatalogo::create(['nombre' => 'Standard', 'slug' => 'standard', 'orden' => 1]);
        $modificado = CategoriaCatalogo::create(['nombre' => 'Modificado', 'slug' => 'modificado', 'orden' => 2]);
        $extremo = CategoriaCatalogo::create(['nombre' => 'Extremo', 'slug' => 'extremo', 'orden' => 3]);

        // ========================================
        // FECHA FINALIZADA
        // ========================================
        $fechaFin = Fecha::create([
            'nombre' => 'Desafio Hernandarias - Abril 2026',
            'fecha' => '2026-04-15',
            'tipo_pista' => 'doble',
            'vueltas_clasificacion' => 2,
            'tiene_final' => true,
            'finalistas_top' => 3,
            'estado' => 'finalizada',
        ]);

        $fcFinStd = FechaCategoria::create([
            'fecha_id' => $fechaFin->id,
            'categoria_catalogo_id' => $standard->id,
            'penal_estaca_seg' => 5,
            'penal_cinta_seg' => 10,
            'fase' => 'finalizada',
        ]);

        $fcFinMod = FechaCategoria::create([
            'fecha_id' => $fechaFin->id,
            'categoria_catalogo_id' => $modificado->id,
            'penal_estaca_seg' => 8,
            'penal_cinta_seg' => 15,
            'fase' => 'finalizada',
        ]);

        // Standard finalizada: 4 tripulaciones con 2 vueltas + final top 3
        $this->crearTripConVueltas($fcFinStd, '01', 'Los Guaranies', 'Carlos Benitez', 'Miguel Torres', 'completada', 1, [
            [1, 'clasificacion', [[125400, 1, 0], [132800, 0, 1]]],
            [2, 'clasificacion', [[121200, 0, 0], [128500, 1, 0]]],
            [99, 'final', [[118900, 0, 0], [125100, 0, 0]]],
        ]);
        $this->crearTripConVueltas($fcFinStd, '07', 'Team Chaco', 'Roberto Gimenez', 'Andres Rojas', 'completada', 2, [
            [1, 'clasificacion', [[134200, 2, 0], [129600, 0, 1]]],
            [2, 'clasificacion', [[130100, 1, 0], [127800, 0, 0]]],
            [99, 'final', [[126500, 1, 0], [123900, 0, 0]]],
        ]);
        $this->crearTripConVueltas($fcFinStd, '12', 'Nanduti Racing', 'Fernando Acosta', 'Luis Cabrera', 'completada', 3, [
            [1, 'clasificacion', [[141500, 1, 1], [138200, 0, 0]]],
            [2, 'clasificacion', [[137800, 0, 0], [135600, 0, 1]]],
            [99, 'final', [[132400, 0, 0], [130800, 1, 0]]],
        ]);
        $this->crearTripConVueltas($fcFinStd, '15', 'Ruta Salvaje', 'Diego Villalba', 'Pedro Ortiz', 'completada', 4, [
            [1, 'clasificacion', [[148900, 0, 2], [145300, 1, 0]]],
            [2, 'clasificacion', [[144200, 0, 1], [141800, 0, 0]]],
        ]);

        // Modificado finalizada: 3 tripulaciones
        $this->crearTripConVueltas($fcFinMod, '02', 'Pantanal 4x4', 'Marcos Lezcano', 'Jorge Cardozo', 'completada', 1, [
            [1, 'clasificacion', [[155000, 3, 1], [148200, 0, 0]]],
            [2, 'clasificacion', [[149800, 1, 0], [145600, 0, 1]]],
            [99, 'final', [[143200, 0, 0], [140500, 1, 0]]],
        ]);
        $this->crearTripConVueltas($fcFinMod, '08', 'Aguara Guazu', 'Pablo Duarte', 'Ramon Fleitas', 'completada', 2, [
            [1, 'clasificacion', [[160200, 0, 2], [155400, 1, 0]]],
            [2, 'clasificacion', [[157100, 0, 0], [152800, 0, 0]]],
            [99, 'final', [[148900, 0, 1], [145200, 0, 0]]],
        ]);
        $this->crearTripConVueltas($fcFinMod, '14', 'Taguato Racing', 'Oscar Sanchez', 'Felix Lopez', 'completada', 3, [
            [1, 'clasificacion', [[165800, 1, 1], [160400, 0, 0]]],
            [2, 'clasificacion', [[162300, 0, 1], [158900, 1, 0]]],
            [99, 'final', [[155100, 0, 0], [151800, 0, 0]]],
        ]);

        // ========================================
        // FECHA ACTIVA
        // ========================================
        $fechaAct = Fecha::create([
            'nombre' => 'Gran Premio CDE - Mayo 2026',
            'fecha' => '2026-05-03',
            'tipo_pista' => 'doble',
            'vueltas_clasificacion' => 3,
            'tiene_final' => true,
            'finalistas_top' => 3,
            'estado' => 'activa',
        ]);

        // Standard: fase FINAL (todas las trips completaron 3 vueltas)
        $fcActStd = FechaCategoria::create([
            'fecha_id' => $fechaAct->id,
            'categoria_catalogo_id' => $standard->id,
            'penal_estaca_seg' => 5,
            'penal_cinta_seg' => 10,
            'fase' => 'final',
        ]);

        $this->crearTripConVueltas($fcActStd, '03', 'Toro Salvaje', 'Raul Mendoza', 'Julio Espinola', 'completada', 1, [
            [1, 'clasificacion', [[118500, 1, 0], [122300, 0, 0]]],
            [2, 'clasificacion', [[115200, 0, 0], [119800, 0, 1]]],
            [3, 'clasificacion', [[116800, 0, 0], [120100, 1, 0]]],
            [99, 'final', [[113400, 0, 0], [117200, 0, 0]]],
        ]);
        $this->crearTripConVueltas($fcActStd, '05', 'Kaaguy Team', 'Sebastian Franco', 'Adrian Nunez', 'completada', 2, [
            [1, 'clasificacion', [[124700, 0, 1], [119800, 2, 0]]],
            [2, 'clasificacion', [[120300, 1, 0], [118500, 0, 0]]],
            [3, 'clasificacion', [[122100, 0, 0], [117900, 0, 0]]],
            [99, 'final', [[116800, 0, 0], [114500, 0, 0]]],
        ]);
        $this->crearTripConVueltas($fcActStd, '09', 'Ypane Off-Road', 'Gustavo Riveros', 'Hector Duarte', 'completada', 3, [
            [1, 'clasificacion', [[131200, 0, 0], [128400, 0, 0]]],
            [2, 'clasificacion', [[128900, 1, 0], [126300, 0, 0]]],
            [3, 'clasificacion', [[127500, 0, 1], [125800, 0, 0]]],
        ]);
        $this->crearTripConVueltas($fcActStd, '11', 'Mbopi Racing', 'Alfredo Caceres', 'Ramon Gauto', 'completada', 4, [
            [1, 'clasificacion', [[128900, 1, 1], [126500, 0, 0]]],
            [2, 'clasificacion', [[125400, 0, 0], [123800, 0, 0]]],
            [3, 'clasificacion', [[126200, 0, 1], [124100, 0, 0]]],
        ]);

        // Modificado: fase CLASIFICACION (en progreso)
        $fcActMod = FechaCategoria::create([
            'fecha_id' => $fechaAct->id,
            'categoria_catalogo_id' => $modificado->id,
            'penal_estaca_seg' => 8,
            'penal_cinta_seg' => 15,
            'fase' => 'clasificacion',
        ]);

        $this->crearTripConVueltas($fcActMod, '17', 'Pira Pyta', 'Eduardo Sanchez', 'Oscar Benitez', 'completada', 1, [
            [1, 'clasificacion', [[142300, 0, 1], [138500, 1, 0]]],
            [2, 'clasificacion', [[139800, 0, 0], [136200, 0, 0]]],
            [3, 'clasificacion', [[141100, 1, 0], [137400, 0, 0]]],
        ]);
        $this->crearTripConVueltas($fcActMod, '21', 'Jaguarete 4x4', 'Cristian Ayala', 'Mario Fleitas', 'en_pista', 2, [
            [1, 'clasificacion', [[148700, 1, 0], [144200, 0, 1]]],
            [2, 'clasificacion', [[145300, 0, 0], [141800, 0, 0]]],
        ]);
        $this->crearTripConVueltas($fcActMod, '25', 'Cerro Cora', 'Tomas Vera', 'Fabian Lopez', 'en_pista', 3, [
            [1, 'clasificacion', [[152400, 0, 2], [148900, 1, 0]]],
        ]);
        $this->crearTripConVueltas($fcActMod, '30', 'Kuarahy Team', 'German Aguilera', 'Rene Paredes', 'inscripta', 4, []);

        // Extremo: fase CLASIFICACION (recien empezo)
        $fcActExt = FechaCategoria::create([
            'fecha_id' => $fechaAct->id,
            'categoria_catalogo_id' => $extremo->id,
            'penal_estaca_seg' => 10,
            'penal_cinta_seg' => 20,
            'fase' => 'clasificacion',
        ]);

        $this->crearTripConVueltas($fcActExt, '33', 'Yakare Team', 'Hugo Martinez', 'Daniel Ortega', 'en_pista', 1, [
            [1, 'clasificacion', [[165200, 2, 1], [158900, 0, 0]]],
        ]);
        $this->crearTripConVueltas($fcActExt, '35', 'Tatakua 4x4', 'Nelson Gonzalez', 'Victor Romero', 'inscripta', 2, []);
        $this->crearTripConVueltas($fcActExt, '38', 'Karumbe Racing', 'Ricardo Alvarez', 'Sergio Dominguez', 'inscripta', 3, []);
    }

    private function crearTripConVueltas(
        FechaCategoria $fc, string $numero, string $nombre,
        string $piloto, string $copiloto, string $estado,
        int $orden, array $vueltasData
    ): void {
        $trip = Tripulacion::create([
            'fecha_categoria_id' => $fc->id,
            'numero' => $numero,
            'nombre' => $nombre,
            'piloto' => $piloto,
            'copiloto' => $copiloto,
            'estado' => $estado,
            'orden_largada' => $orden,
        ]);

        foreach ($vueltasData as $vd) {
            [$numVuelta, $fase, $tramosData] = $vd;

            $vuelta = $trip->vueltas()->create([
                'numero_vuelta' => $numVuelta,
                'fase' => $fase,
                'nula' => false,
            ]);

            // tramosData: [[tiempo_ms_A, estacas_A, cintas_A], [tiempo_ms_B, estacas_B, cintas_B]]
            // o solo [[tiempo_ms, estacas, cintas]] para pista simple
            $letras = ['A', 'B'];
            foreach ($tramosData as $i => $td) {
                $vuelta->tramos()->create([
                    'letra' => $letras[$i],
                    'tiempo_ms' => $td[0],
                    'estacas' => $td[1],
                    'cintas' => $td[2],
                ]);
            }
        }
    }
}
