<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Categoria;
use App\Models\Item;

class MigrarCategoriasItems extends Command
{
    protected $signature = 'inv:migrar-categorias';
    protected $description = 'Crea categorias desde items.categoria (texto) y asigna categoria_id';

    public function handle(): int
    {
        $items = Item::query()
            ->select('id', 'categoria')
            ->whereNotNull('categoria')
            ->where('categoria', '!=', '')
            ->get();

        $cache = [];

        foreach ($items as $it) {
            $nombre = trim((string) $it->categoria);
            if ($nombre === '')
                continue;

            if (!isset($cache[$nombre])) {
                $cat = Categoria::firstOrCreate(
                    ['nombre' => $nombre],
                    ['activo' => true]
                );
                $cache[$nombre] = $cat->id;
            }

            $it->categoria_id = $cache[$nombre];
            $it->save();
        }

        $this->info('OK: categorias migradas y categoria_id asignado.');
        return self::SUCCESS;
    }
}
