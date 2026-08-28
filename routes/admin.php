<?php

use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
| Requiere login.
| Cada acción de usuarios se protege por permiso específico (usuarios.*).
*/

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth'])
    ->group(function () {

        // Usuarios - listado
        Route::get('users', [UserController::class, 'index'])
            ->name('users.index')
            ->middleware('permission:usuarios.ver');

        // Usuarios - crear
        Route::get('users/create', [UserController::class, 'create'])
            ->name('users.create')
            ->middleware('permission:usuarios.crear');

        Route::post('users', [UserController::class, 'store'])
            ->name('users.store')
            ->middleware('permission:usuarios.crear');

        // Usuarios - editar
        Route::get('users/{user}/edit', [UserController::class, 'edit'])
            ->name('users.edit')
            ->middleware('permission:usuarios.editar');

        Route::put('users/{user}', [UserController::class, 'update'])
            ->name('users.update')
            ->middleware('permission:usuarios.editar');

        // Usuarios - eliminar
        Route::delete('users/{user}', [UserController::class, 'destroy'])
            ->name('users.destroy')
            ->middleware('permission:usuarios.eliminar');
    });
