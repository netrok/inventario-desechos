<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    /**
     * Normaliza campos servidor-side: trim, RFC en mayúsculas, email en minúsculas.
     */
    private function normalizar(Request $request): array
    {
        $data = $request->validate([
            'tipo' => ['required', Rule::in(Cliente::TIPOS)],
            'nombre' => ['required', 'string', 'max:255'],
            'rfc' => ['nullable', 'string', 'max:20'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'direccion' => ['nullable', 'string', 'max:2000'],
            'notas' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['nombre'] = trim($data['nombre']);

        // Campos ausentes se tratan como null (formularios independientes del catálogo).
        $data['rfc'] = $request->filled('rfc') ? mb_strtoupper(trim($request->input('rfc'))) : null;
        $data['email'] = $request->filled('email') ? mb_strtolower(trim($request->input('email'))) : null;
        $data['telefono'] = $request->filled('telefono') ? trim($request->input('telefono')) : null;
        $data['direccion'] = $request->filled('direccion') ? trim($request->input('direccion')) : null;
        $data['notas'] = $request->filled('notas') ? trim($request->input('notas')) : null;

        return $data;
    }

    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'tipo' => $request->query('tipo') ?: null,
            'activo' => $request->has('activo') ? $request->query('activo') : null,
        ];

        $clientes = Cliente::query()
            ->when($filters['q'] !== '', fn ($qq) => $qq->where(function ($w) use ($filters) {
                $w->where('nombre', 'ilike', "%{$filters['q']}%")
                    ->orWhere('codigo', 'ilike', "%{$filters['q']}%")
                    ->orWhere('rfc', 'ilike', "%{$filters['q']}%")
                    ->orWhere('telefono', 'ilike', "%{$filters['q']}%")
                    ->orWhere('email', 'ilike', "%{$filters['q']}%");
            }))
            ->when($filters['tipo'], fn ($qq) => $qq->where('tipo', $filters['tipo']))
            ->when($filters['activo'] !== null && $filters['activo'] !== '', fn ($qq) => $qq->where('activo', $filters['activo'] === '1'))
            ->withCount('ventas')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('clientes.index', [
            'clientes' => $clientes,
            'filters' => $filters,
            'tipos' => Cliente::TIPOS,
        ]);
    }

    public function create()
    {
        return view('clientes.create', ['tipos' => Cliente::TIPOS]);
    }

    public function store(Request $request)
    {
        $data = $this->normalizar($request);
        $data['activo'] = true;

        Cliente::create($data);

        return redirect()
            ->route('clientes.index')
            ->with('success', 'Cliente registrado.');
    }

    public function show(Cliente $cliente)
    {
        $ventas = $cliente->ventas()
            ->with('user')
            ->withCount('detalles')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('clientes.show', ['cliente' => $cliente, 'ventas' => $ventas]);
    }

    public function edit(Cliente $cliente)
    {
        return view('clientes.edit', [
            'cliente' => $cliente,
            'tipos' => Cliente::TIPOS,
        ]);
    }

    public function update(Request $request, Cliente $cliente)
    {
        $cliente->update($this->normalizar($request));

        return redirect()
            ->route('clientes.show', $cliente)
            ->with('success', 'Cliente actualizado.');
    }

    /**
     * Desactivar/reactivar un cliente (permiso clientes.desactivar).
     * El ciclo de vida es ACTIVO <-> INACTIVO sin borrado físico.
     */
    public function toggleActivo(Cliente $cliente)
    {
        $cliente->update(['activo' => ! $cliente->activo]);

        $estado = $cliente->activo ? 'reactivado' : 'desactivado';

        return back()->with('success', "Cliente {$cliente->codigo} {$estado}.");
    }

    /**
     * Autocomplete server-side para el POS (permiso clientes.ver).
     * No carga miles de clientes: búsqueda limitada por código/nombre/tel/RFC/email.
     */
    public function search(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $q = trim((string) ($data['q'] ?? ''));

        $clientes = Cliente::query()
            ->activos()
            ->when($q !== '', fn ($qq) => $qq->where(function ($w) use ($q) {
                $w->where('codigo', 'ilike', "%{$q}%")
                    ->orWhere('nombre', 'ilike', "%{$q}%")
                    ->orWhere('telefono', 'ilike', "%{$q}%")
                    ->orWhere('rfc', 'ilike', "%{$q}%")
                    ->orWhere('email', 'ilike', "%{$q}%");
            }))
            ->orderBy('nombre')
            ->limit(20)
            ->get(['id', 'codigo', 'tipo', 'nombre', 'rfc', 'telefono', 'email']);

        return response()->json(['clientes' => $clientes]);
    }

    /**
     * Alta rápida de cliente desde el POS (permiso clientes.crear).
     * Crea el cliente, lo deja seleccionado en la sesión del POS y redirige
     * de vuelta al carrito SIN perderlo. Todas las validaciones son server-side.
     */
    public function rapida(Request $request)
    {
        $data = $this->normalizar($request);
        $data['activo'] = true;

        $cliente = Cliente::create($data);

        session(['pos.cliente_id' => $cliente->id]);

        return redirect()->route('pos.index')
            ->with('success', "Cliente {$cliente->codigo} creado y seleccionado.");
    }
}
