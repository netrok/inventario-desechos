<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Ubicacion;

class UbicacionesSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            ['nombre' => 'Almacén Principal', 'codigo' => 'ALM-PRINC'],
            ['nombre' => 'Bodega',           'codigo' => 'BOD-01'],
            ['nombre' => 'Taller',           'codigo' => 'TALLER'],
        ];

        foreach ($data as $row) {
            Ubicacion::firstOrCreate(
                ['nombre' => $row['nombre']],
                $row
            );
        }
    }
}
