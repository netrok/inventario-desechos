<?php

use App\Models\Item;
use App\Models\Movimiento;
use App\Models\Ubicacion;
use App\Models\User;
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

it('define movimientos.user_id como FK RESTRICT hacia users', function () {
    $row = DB::selectOne("
        select c.confdeltype, rel.relname as ref_table
        from pg_constraint c
        join pg_class t on t.oid = c.conrelid
        join pg_class rel on rel.oid = c.confrelid
        where t.relname = 'movimientos'
          and c.conname = 'movimientos_user_id_foreign'
    ");

    expect($row)->not->toBeNull();
    expect($row->confdeltype)->toBe('r'); // r = RESTRICT
    expect($row->ref_table)->toBe('users');
});

it('define movimientos.de_ubicacion_id y a_ubicacion_id como FK RESTRICT hacia ubicaciones', function () {
    foreach (['movimientos_de_ubicacion_id_foreign', 'movimientos_a_ubicacion_id_foreign'] as $constraint) {
        $row = DB::selectOne("
            select c.confdeltype, rel.relname as ref_table
            from pg_constraint c
            join pg_class t on t.oid = c.conrelid
            join pg_class rel on rel.oid = c.confrelid
            where t.relname = 'movimientos'
              and c.conname = :name
        ", ['name' => $constraint]);

        expect($row)->not->toBeNull();
        expect($row->confdeltype)->toBe('r'); // r = RESTRICT
        expect($row->ref_table)->toBe('ubicaciones');
    }
});

it('las columnas de actor y ubicaciones siguen siendo nullable en movimientos', function () {
    foreach (['user_id', 'de_ubicacion_id', 'a_ubicacion_id'] as $column) {
        $row = DB::selectOne("
            select is_nullable
            from information_schema.columns
            where table_name = 'movimientos' and column_name = :column
        ", ['column' => $column]);

        expect($row)->not->toBeNull();
        expect($row->is_nullable)->toBe('YES');
    }
});

it('impide el borrado fisico de un usuario con movimientos y conserva el actor', function () {
    $user = User::factory()->create();
    $item = Item::create(['estado' => 'DISPONIBLE']);

    $movimiento = Movimiento::create([
        'item_id' => $item->id,
        'user_id' => $user->id,
        'tipo' => 'ALTA',
        'a_estado' => 'DISPONIBLE',
    ]);

    $threw = false;
    try {
        DB::transaction(function () use ($user) {
            $user->delete();
        });
    } catch (QueryException $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
    $this->assertDatabaseHas('users', ['id' => $user->id]);
    $this->assertDatabaseHas('movimientos', ['id' => $movimiento->id, 'user_id' => $user->id]);
});

it('impide el borrado fisico de una ubicacion referenciada por movimientos y conserva la referencia', function () {
    $ubicacion = Ubicacion::create(['nombre' => 'Histórica']);
    $item = Item::create(['estado' => 'DISPONIBLE']);

    $movimiento = Movimiento::create([
        'item_id' => $item->id,
        'tipo' => 'ALTA',
        'a_estado' => 'DISPONIBLE',
        'de_ubicacion_id' => $ubicacion->id,
    ]);

    $threw = false;
    try {
        DB::transaction(function () use ($ubicacion) {
            $ubicacion->delete();
        });
    } catch (QueryException $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
    $this->assertDatabaseHas('ubicaciones', ['id' => $ubicacion->id]);
    $this->assertDatabaseHas('movimientos', ['id' => $movimiento->id, 'de_ubicacion_id' => $ubicacion->id]);
});
