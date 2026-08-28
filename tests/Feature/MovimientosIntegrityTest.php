<?php

use App\Models\Item;
use App\Models\Movimiento;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('define la FK de movimientos.item_id como RESTRICT en PostgreSQL', function () {
    $row = DB::selectOne("
        select c.confdeltype
        from pg_constraint c
        join pg_class t on t.oid = c.conrelid
        where t.relname = 'movimientos'
          and c.conname = 'movimientos_item_id_foreign'
    ");

    expect($row)->not->toBeNull();
    expect($row->confdeltype)->toBe('r'); // r = RESTRICT
});

it('impide el borrado fisico de un item con movimientos y conserva el historial en la BD', function () {
    $item = Item::create(['estado' => 'DISPONIBLE']);

    Movimiento::create([
        'item_id' => $item->id,
        'tipo' => 'ALTA',
        'a_estado' => 'DISPONIBLE',
    ]);

    $countItem = function () use ($item): int {
        return (int) DB::selectOne('select count(*) as c from items where id = ?', [$item->id])->c;
    };

    $countMovimiento = function () use ($item): int {
        return (int) DB::selectOne('select count(*) as c from movimientos where item_id = ?', [$item->id])->c;
    };

    expect($countItem())->toBe(1);
    expect($countMovimiento())->toBe(1);

    $threw = false;
    try {
        DB::transaction(function () use ($item) {
            $item->forceDelete();
        });
    } catch (QueryException $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue();

    expect($countItem())->toBe(1);
    expect($countMovimiento())->toBe(1);
    expect(DB::table('items')->where('id', $item->id)->value('deleted_at'))->toBeNull();
});

it('permite el soft delete de un item conservando sus movimientos', function () {
    $item = Item::create(['estado' => 'DISPONIBLE']);

    Movimiento::create([
        'item_id' => $item->id,
        'tipo' => 'ALTA',
        'a_estado' => 'DISPONIBLE',
    ]);

    $item->delete();

    $this->assertSoftDeleted('items', ['id' => $item->id]);
    $this->assertDatabaseHas('movimientos', ['item_id' => $item->id]);
});
