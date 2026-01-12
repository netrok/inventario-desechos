<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ItemController;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware(['auth'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])
        // ->middleware('verified') // opcional
        ->name('dashboard');

    // Perfil
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Items - Papelera
    Route::get('items-trash', [ItemController::class, 'trash'])->name('items.trash');
    Route::post('items/{id}/restore', [ItemController::class, 'restore'])->name('items.restore');
    Route::delete('items/{id}/force', [ItemController::class, 'forceDelete'])->name('items.forceDelete');

    // Items - Acciones rápidas
    Route::post('items/{id}/estado', [ItemController::class, 'changeEstado'])->name('items.changeEstado');
    Route::post('items/{id}/mover',  [ItemController::class, 'moveUbicacion'])->name('items.moveUbicacion');

    // Items - CRUD
    Route::resource('items', ItemController::class);
});

require __DIR__.'/auth.php';
