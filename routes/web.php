<?php

use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UbicacionController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware(['auth'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard')
        ->middleware('permission:dashboard.ver');

    // Perfil
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    /**
     * =========================
     * Catálogos
     * =========================
     */
    Route::get('categorias', [CategoriaController::class, 'index'])
        ->name('categorias.index')
        ->middleware('permission:categorias.ver');

    Route::get('categorias/create', [CategoriaController::class, 'create'])
        ->name('categorias.create')
        ->middleware('permission:categorias.crear');

    Route::post('categorias', [CategoriaController::class, 'store'])
        ->name('categorias.store')
        ->middleware('permission:categorias.crear');

    Route::get('categorias/{categoria}/edit', [CategoriaController::class, 'edit'])
        ->name('categorias.edit')
        ->middleware('permission:categorias.editar');

    Route::put('categorias/{categoria}', [CategoriaController::class, 'update'])
        ->name('categorias.update')
        ->middleware('permission:categorias.editar');

    Route::delete('categorias/{categoria}', [CategoriaController::class, 'destroy'])
        ->name('categorias.destroy')
        ->middleware('permission:categorias.eliminar');

    Route::get('ubicaciones', [UbicacionController::class, 'index'])
        ->name('ubicaciones.index')
        ->middleware('permission:ubicaciones.ver');

    Route::get('ubicaciones/create', [UbicacionController::class, 'create'])
        ->name('ubicaciones.create')
        ->middleware('permission:ubicaciones.crear');

    Route::post('ubicaciones', [UbicacionController::class, 'store'])
        ->name('ubicaciones.store')
        ->middleware('permission:ubicaciones.crear');

    Route::get('ubicaciones/{ubicacion}/edit', [UbicacionController::class, 'edit'])
        ->name('ubicaciones.edit')
        ->middleware('permission:ubicaciones.editar');

    Route::put('ubicaciones/{ubicacion}', [UbicacionController::class, 'update'])
        ->name('ubicaciones.update')
        ->middleware('permission:ubicaciones.editar');

    Route::delete('ubicaciones/{ubicacion}', [UbicacionController::class, 'destroy'])
        ->name('ubicaciones.destroy')
        ->middleware('permission:ubicaciones.eliminar');

    /**
     * =========================
     * Items
     * =========================
     */
    // Export (solo ver)
    Route::get('items/export/xlsx', [ItemController::class, 'exportXlsx'])
        ->name('items.export.xlsx')
        ->middleware('permission:items.ver');

    Route::get('items/export/pdf', [ItemController::class, 'exportPdf'])
        ->name('items.export.pdf')
        ->middleware('permission:items.ver');

    // Acciones rápidas (editar)
    Route::post('items/{id}/estado', [ItemController::class, 'changeEstado'])
        ->name('items.changeEstado')
        ->middleware('permission:items.cambiar_estado');

    Route::post('items/{id}/mover', [ItemController::class, 'moveUbicacion'])
        ->name('items.moveUbicacion')
        ->middleware('permission:items.mover');

    // CRUD principal
    Route::get('items', [ItemController::class, 'index'])->name('items.index')->middleware('permission:items.ver');
    Route::get('items/scan', [ItemController::class, 'scan'])->name('items.scan')->middleware('permission:items.ver');
    Route::get('items/create', [ItemController::class, 'create'])->name('items.create')->middleware('permission:items.crear');
    Route::post('items', [ItemController::class, 'store'])->name('items.store')->middleware('permission:items.crear');
    Route::get('items/{item}', [ItemController::class, 'show'])->name('items.show')->middleware('permission:items.ver');
    Route::get('items/{item}/label', [ItemController::class, 'label'])->name('items.label')->middleware('permission:items.ver');
    Route::get('items/{item}/edit', [ItemController::class, 'edit'])->name('items.edit')->middleware('permission:items.editar');
    Route::put('items/{item}', [ItemController::class, 'update'])->name('items.update')->middleware('permission:items.editar');
});

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
