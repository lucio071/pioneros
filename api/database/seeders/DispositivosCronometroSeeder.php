<?php

namespace Database\Seeders;

use App\Models\DispositivoCronometro;
use Illuminate\Database\Seeder;

class DispositivosCronometroSeeder extends Seeder
{
    public function run(): void
    {
        $dispositivos = [
            ['codigo' => 'crono-a',  'tipo' => 'cronometro', 'tramo' => 'A',    'nombre' => 'Cronometro Pista A'],
            ['codigo' => 'crono-b',  'tipo' => 'cronometro', 'tramo' => 'B',    'nombre' => 'Cronometro Pista B'],
            ['codigo' => 'sensor-a', 'tipo' => 'sensor',     'tramo' => 'A',    'nombre' => 'Sensor Pista A'],
            ['codigo' => 'sensor-b', 'tipo' => 'sensor',     'tramo' => 'B',    'nombre' => 'Sensor Pista B'],
            ['codigo' => 'semaforo', 'tipo' => 'semaforo',    'tramo' => null,   'nombre' => 'Semaforo de largada'],
        ];

        foreach ($dispositivos as $d) {
            DispositivoCronometro::firstOrCreate(
                ['codigo' => $d['codigo']],
                $d
            );
        }
    }
}
