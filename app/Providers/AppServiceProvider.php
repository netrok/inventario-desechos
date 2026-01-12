<?php

namespace App\Providers;

use App\Models\Item;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // ✅ Badge de papelera en el menú
        View::composer('layouts.navigation', function ($view) {
            $view->with('itemsTrashCount', Item::onlyTrashed()->count());
        });

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
