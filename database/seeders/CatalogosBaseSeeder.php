<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Categoria;
use App\Models\Ubicacion;

class CatalogosBaseSeeder extends Seeder
{
    public function run(): void
    {
        // Categorías base
        $categorias = ['Laptop','PC','Impresora','Monitor','Celular','Tablet','Accesorios','Refacciones'];
        foreach ($categorias as $c) {
            Categoria::firstOrCreate(['nombre' => $c]);
        }

        // Ubicaciones base
        $ubicaciones = [
            ['nombre' => 'Almacén', 'descripcion' => 'Bodega principal'],
            ['nombre' => 'Taller', 'descripcion' => 'Área de reparación'],
            ['nombre' => 'Tienda', 'descripcion' => 'Piso de venta'],
        ];

        foreach ($ubicaciones as $u) {
            Ubicacion::firstOrCreate(
                ['nombre' => $u['nombre']],
                ['descripcion' => $u['descripcion']]
            );
        }
    }
}
