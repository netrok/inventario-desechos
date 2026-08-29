<?php

use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PostventaController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\UbicacionController;
use App\Http\Controllers\VentaController;
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
    // Sin DELETE /profile: las cuentas se administran vía usuarios.eliminar.

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
     * Clientes
     * =========================
     */
    // Autocomplete server-side para el POS (consulta).
    Route::get('clientes/search', [ClienteController::class, 'search'])
        ->name('clientes.search')
        ->middleware('permission:clientes.ver');

    // Alta rápida de cliente desde el POS (no pierde el carrito).
    Route::post('clientes/rapida', [ClienteController::class, 'rapida'])
        ->name('clientes.rapida')
        ->middleware('permission:clientes.crear');

    Route::get('clientes', [ClienteController::class, 'index'])
        ->name('clientes.index')
        ->middleware('permission:clientes.ver');

    Route::get('clientes/create', [ClienteController::class, 'create'])
        ->name('clientes.create')
        ->middleware('permission:clientes.crear');

    Route::post('clientes', [ClienteController::class, 'store'])
        ->name('clientes.store')
        ->middleware('permission:clientes.crear');

    Route::get('clientes/{cliente}', [ClienteController::class, 'show'])
        ->name('clientes.show')
        ->middleware('permission:clientes.ver');

    Route::get('clientes/{cliente}/edit', [ClienteController::class, 'edit'])
        ->name('clientes.edit')
        ->middleware('permission:clientes.editar');

    Route::put('clientes/{cliente}', [ClienteController::class, 'update'])
        ->name('clientes.update')
        ->middleware('permission:clientes.editar');

    // Desactivar/reactivar (protegido por clientes.desactivar).
    Route::post('clientes/{cliente}/toggle', [ClienteController::class, 'toggleActivo'])
        ->name('clientes.toggle')
        ->middleware('permission:clientes.desactivar');

    /**
     * =========================
     * Configuración general (solo Admin)
     * =========================
     */
    Route::get('configuracion', [ConfiguracionController::class, 'edit'])
        ->name('configuracion.edit')
        ->middleware('permission:configuracion.ver');

    Route::put('configuracion', [ConfiguracionController::class, 'update'])
        ->name('configuracion.update')
        ->middleware('permission:configuracion.editar');

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

    /**
     * =========================
     * Punto de venta (POS operativo: ventas.crear)
     * =========================
     */
    // POS / ventas
    Route::get('pos', [PosController::class, 'index'])
        ->name('pos.index')
        ->middleware('permission:ventas.crear');

    // Acciones sobre el carrito (solo ventas.crear; Auditor es solo lectura)
    Route::post('pos/agregar', [PosController::class, 'add'])
        ->name('pos.add')
        ->middleware('permission:ventas.crear');

    Route::post('pos/quitar', [PosController::class, 'remove'])
        ->name('pos.remove')
        ->middleware('permission:ventas.crear');

    // Seleccionar/limpiar cliente del POS (ventas.crear; clientes.ver para elegir)
    Route::post('pos/cliente', [PosController::class, 'setCliente'])
        ->name('pos.cliente')
        ->middleware(['permission:ventas.crear', 'permission:clientes.ver']);

    Route::post('pos/cliente/limpiar', [PosController::class, 'clearCliente'])
        ->name('pos.cliente.limpiar')
        ->middleware('permission:ventas.crear');

    // Confirmación de venta (atómica)
    Route::post('pos/checkout', [PosController::class, 'checkout'])
        ->name('pos.checkout')
        ->middleware('permission:ventas.crear');

    /**
     * =========================
     * Consulta histórica de ventas (ventas.ver)
     * =========================
     */
    Route::get('ventas', [VentaController::class, 'index'])
        ->name('ventas.index')
        ->middleware('permission:ventas.ver');

    Route::get('ventas/{venta}', [VentaController::class, 'show'])
        ->name('ventas.show')
        ->middleware('permission:ventas.ver');

    // Ticket imprimible (consultas: imprimir/reimprimir es lectura).
    Route::get('ventas/{venta}/ticket', [VentaController::class, 'ticket'])
        ->name('ventas.ticket')
        ->middleware('permission:ventas.ver');

    /**
     * =========================
     * Postventa: cancelaciones y devoluciones atómicas
     * =========================
     */
    // Cancelación (Admin): reversa TOTAL. El GET solo muestra el formulario.
    Route::get('ventas/{venta}/cancelar', [PostventaController::class, 'cancelarForm'])
        ->name('ventas.cancelar')
        ->middleware('permission:ventas.cancelar');

    Route::post('ventas/{venta}/cancelar', [PostventaController::class, 'cancelar'])
        ->name('ventas.cancelar.store')
        ->middleware('permission:ventas.cancelar');

    // Devolución (Admin + Ventas): parcial o total.
    Route::get('ventas/{venta}/devolver', [PostventaController::class, 'devolverForm'])
        ->name('ventas.devolver')
        ->middleware('permission:ventas.devolver');

    Route::post('ventas/{venta}/devolver', [PostventaController::class, 'devolver'])
        ->name('ventas.devolver.store')
        ->middleware('permission:ventas.devolver');

    // Consulta de documentos postventa (Auditor puede consultarlos vía ventas.ver).
    Route::get('postventa/{documento}', [PostventaController::class, 'show'])
        ->name('postventa.show')
        ->middleware('permission:ventas.ver');

    Route::get('postventa/{documento}/print', [PostventaController::class, 'print'])
        ->name('postventa.print')
        ->middleware('permission:ventas.ver');

    /**
     * =========================
     * Reportes operativos
     * =========================
     */
    Route::get('reports', [ReportController::class, 'index'])
        ->name('reports.index')
        ->middleware('permission:reportes.ver');

    Route::get('reports/inventory', [ReportController::class, 'inventory'])
        ->name('reports.inventory')
        ->middleware('permission:reportes.ver');

    Route::get('reports/inventory.xlsx', [ReportController::class, 'inventoryXlsx'])
        ->name('reports.inventory.xlsx')
        ->middleware('permission:reportes.ver');

    Route::get('reports/inventory.pdf', [ReportController::class, 'inventoryPdf'])
        ->name('reports.inventory.pdf')
        ->middleware('permission:reportes.ver');

    Route::get('reports/movimientos', [ReportController::class, 'movimientos'])
        ->name('reports.movimientos')
        ->middleware('permission:reportes.ver');

    Route::get('reports/movimientos.xlsx', [ReportController::class, 'movimientosXlsx'])
        ->name('reports.movimientos.xlsx')
        ->middleware('permission:reportes.ver');

    Route::get('reports/movimientos.pdf', [ReportController::class, 'movimientosPdf'])
        ->name('reports.movimientos.pdf')
        ->middleware('permission:reportes.ver');
});

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
