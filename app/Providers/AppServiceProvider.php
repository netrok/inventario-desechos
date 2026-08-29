<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // ✅ Forzar nombres de parámetros en resource routes (para evitar "ubicacione")
        Route::resourceParameters([
            'ubicaciones' => 'ubicacion',
            'categorias' => 'categoria',
        ]);

        // (Opcional) Verbs, en realidad ya son create/edit por default
        Route::resourceVerbs([
            'create' => 'create',
            'edit' => 'edit',
        ]);
    }
}
