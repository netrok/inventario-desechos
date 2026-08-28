<?php

use App\Models\Item;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function resyncSequenceMigration(): void
{
    DB::table('migrations')
        ->where('migration', '2026_08_27_140000_create_items_codigo_sequence')
        ->delete();

    Artisan::call('migrate');
}

it('asigna códigos monotónicos y distintos en items consecutivos', function () {
    $primero = Item::create(['estado' => 'DISPONIBLE']);
    $segundo = Item::create(['estado' => 'DISPONIBLE']);

    expect((int) $segundo->codigo_seq)->toBeGreaterThan((int) $primero->codigo_seq);
    expect($primero->codigo)->not->toBe($segundo->codigo);
    expect((int) $primero->codigo_seq)->toBeGreaterThanOrEqual(1);
});

it('mantiene el formato ITM-XXXXXX', function () {
    $item = Item::create(['estado' => 'DISPONIBLE']);

    expect($item->codigo)->toMatch('/^ITM-\d{6}$/');
    expect((int) $item->codigo_seq)->toBeGreaterThanOrEqual(1);
});

it('no reutiliza valores consumidos por una transaccion revertida', function () {
    $item1 = Item::create(['estado' => 'DISPONIBLE']);
    $seq1 = (int) $item1->codigo_seq;

    $seq2 = (int) DB::selectOne("select nextval('items_codigo_seq_generator') as seq")->seq;
    expect($seq2)->toBeGreaterThan($seq1);

    try {
        DB::transaction(function (): void {
            DB::selectOne("select nextval('items_codigo_seq_generator') as seq");
            throw new RuntimeException('fuerza rollback despues de consumir la secuencia');
        });
    } catch (RuntimeException) {
        // esperado: el valor consumido se pierde con el rollback
    }

    $item2 = Item::create(['estado' => 'DISPONIBLE']);

    expect((int) $item2->codigo_seq)->toBeGreaterThan($seq2);
    expect($item2->codigo)->toMatch('/^ITM-\d{6}$/');
});

it('los constraints UNIQUE de items protegen duplicados de codigo', function () {
    $item = Item::create(['estado' => 'DISPONIBLE']);

    $threw = false;
    try {
        DB::table('items')->insert([
            'codigo' => $item->codigo,
            'codigo_seq' => (int) $item->codigo_seq + 1,
            'estado' => 'DISPONIBLE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
});

it('los constraints UNIQUE de items protegen duplicados de codigo_seq', function () {
    $item = Item::create(['estado' => 'DISPONIBLE']);

    $threw = false;
    try {
        DB::table('items')->insert([
            'codigo' => 'ITM-999999',
            'codigo_seq' => (int) $item->codigo_seq,
            'estado' => 'DISPONIBLE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
});

it('sincroniza la sequence con codigos legacy sin codigo_seq (ITM-000150 -> >150)', function () {
    DB::table('items')->insert([
        'codigo' => 'ITM-000150',
        'codigo_seq' => null,
        'estado' => 'DISPONIBLE',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    resyncSequenceMigration();

    $nuevo = Item::create(['estado' => 'DISPONIBLE']);

    expect((int) $nuevo->codigo_seq)->toBeGreaterThan(150);
    expect($nuevo->codigo)->not->toBe('ITM-000150');
    expect($nuevo->codigo)->toMatch('/^ITM-\d{6}$/');
});

it('en tabla vacia la sequence recien sincronizada genera ITM-000001', function () {
    resyncSequenceMigration();

    $item = Item::create(['estado' => 'DISPONIBLE']);

    expect((int) $item->codigo_seq)->toBe(1);
    expect($item->codigo)->toBe('ITM-000001');
});
