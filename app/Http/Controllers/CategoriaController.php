<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\Request;

class CategoriaController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string)$request->get('q',''));

        $categorias = Categoria::query()
            ->when($q !== '', fn($qq) => $qq->where('nombre', 'like', "%{$q}%"))
            ->orderBy('nombre')
            ->paginate(12)
            ->withQueryString();

        return view('categorias.index', compact('categorias', 'q'));
    }

    public function create()
    {
        return view('categorias.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => ['required','string','max:80','unique:categorias,nombre'],
            'activo' => ['nullable','boolean'],
        ]);

        $data['activo'] = (bool)($data['activo'] ?? true);

        Categoria::create($data);

        return redirect()
            ->route('categorias.index')
            ->with('success', 'Categoría creada.');
    }

    public function edit(Categoria $categoria)
    {
        return view('categorias.edit', compact('categoria'));
    }

    public function update(Request $request, Categoria $categoria)
    {
        $data = $request->validate([
            'nombre' => ['required','string','max:80','unique:categorias,nombre,' . $categoria->id],
            'activo' => ['nullable','boolean'],
        ]);

        $categoria->update([
            'nombre' => $data['nombre'],
            'activo' => (bool)($data['activo'] ?? false),
        ]);

        return redirect()
            ->route('categorias.index')
            ->with('success', 'Categoría actualizada.');
    }

    public function destroy(Categoria $categoria)
    {
        // Evitar borrar si tiene items (conservador y seguro)
        if ($categoria->items()->exists()) {
            return back()->with('error', 'No se puede eliminar: hay items asignados a esta categoría.');
        }

        $categoria->delete();

        return redirect()
            ->route('categorias.index')
            ->with('success', 'Categoría eliminada.');
    }
}
