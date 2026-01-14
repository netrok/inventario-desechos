<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\UserController;

Route::prefix('admin')
    ->name('admin.')
    ->middleware(['web', 'auth', 'permission:usuarios.gestionar'])
    ->group(function () {
        Route::resource('users', UserController::class)->except(['show']);
    });
