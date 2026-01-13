<?php

namespace App\Http\Controllers;

use App\Models\Categoria;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoriaController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:categorias.ver')->only(['index']);
        $this->middleware('permission:categorias.crear')->only(['create', 'store']);
        $this->middleware('permission:categorias.editar')->only(['edit', 'update']);
        $this->middleware('permission:categorias.eliminar')->only(['destroy']);
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $categorias = Categoria::query()
            ->when($q !== '', function ($qq) use ($q) {
                // Postgres friendly (case-insensitive)
                $qq->where('nombre', 'ilike', "%{$q}%");
            })
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
            'nombre' => ['required', 'string', 'max:80', 'unique:categorias,nombre'],
            'activo' => ['nullable', 'boolean'],
        ]);

        // checkbox: si no viene, default true en alta
        $data['activo'] = (bool) ($request->input('activo', true));

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
            'nombre' => [
                'required',
                'string',
                'max:80',
                Rule::unique('categorias', 'nombre')->ignore($categoria->id),
            ],
            'activo' => ['nullable', 'boolean'],
        ]);

        $categoria->update([
            'nombre' => $data['nombre'],
            // checkbox: si no viene, se considera false
            'activo' => (bool) $request->boolean('activo'),
        ]);

        return redirect()
            ->route('categorias.index')
            ->with('success', 'Categoría actualizada.');
    }

    public function destroy(Categoria $categoria)
    {
        // Conservador y seguro: no borrar si está en uso
        if ($categoria->items()->exists()) {
            return back()->with('error', 'No se puede eliminar: hay items asignados a esta categoría.');
        }

        $categoria->delete();

        return redirect()
            ->route('categorias.index')
            ->with('success', 'Categoría eliminada.');
    }
}
