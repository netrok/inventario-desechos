<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\UbicacionController;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware(['auth'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Perfil
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    /**
     * =========================
     * Catálogos
     * =========================
     */
    Route::middleware('permission:categorias.ver')->group(function () {
        Route::resource('categorias', CategoriaController::class)->except(['show']);
    });

    Route::middleware('permission:ubicaciones.ver')->group(function () {
        Route::resource('ubicaciones', UbicacionController::class)->except(['show']);
    });

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

    // Papelera
    Route::get('items-trash', [ItemController::class, 'trash'])
        ->name('items.trash')
        ->middleware('permission:items.eliminar');

    Route::post('items/{id}/restore', [ItemController::class, 'restore'])
        ->name('items.restore')
        ->middleware('permission:items.eliminar');

    Route::delete('items/{id}/force', [ItemController::class, 'forceDelete'])
        ->name('items.forceDelete')
        ->middleware('permission:items.eliminar');

    // Acciones rápidas (editar)
    Route::post('items/{id}/estado', [ItemController::class, 'changeEstado'])
        ->name('items.changeEstado')
        ->middleware('permission:items.editar');

    Route::post('items/{id}/mover', [ItemController::class, 'moveUbicacion'])
        ->name('items.moveUbicacion')
        ->middleware('permission:items.editar');

    // CRUD principal
    Route::get('items', [ItemController::class, 'index'])->name('items.index')->middleware('permission:items.ver');
    Route::get('items/create', [ItemController::class, 'create'])->name('items.create')->middleware('permission:items.crear');
    Route::post('items', [ItemController::class, 'store'])->name('items.store')->middleware('permission:items.crear');
    Route::get('items/{item}', [ItemController::class, 'show'])->name('items.show')->middleware('permission:items.ver');
    Route::get('items/{item}/edit', [ItemController::class, 'edit'])->name('items.edit')->middleware('permission:items.editar');
    Route::put('items/{item}', [ItemController::class, 'update'])->name('items.update')->middleware('permission:items.editar');
    Route::delete('items/{item}', [ItemController::class, 'destroy'])->name('items.destroy')->middleware('permission:items.eliminar');
});

require __DIR__ . '/auth.php';
require __DIR__ . '/admin.php';
