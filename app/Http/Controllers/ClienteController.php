<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Support\CreditoAcceso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    /**
     * B15.1 — Configuración crediticia.
     *
     * Fuente de verdad: CreditoAcceso::puedeConfigurar() (rol Admin + permiso
     * `creditos.configurar`). Un usuario NO Admin con el permiso directo NO
     * puede modificar estos campos por HTTP, aunque los forje: se devuelve []
     * (los valores actuales se conservan en update; los defaults en create).
     *
     * Request parcial: no se asume que el formulario mande siempre los tres
     * campos. Cada campo AUSENTE conserva el valor actual del Cliente (para
     * update) o el default (para create); cada campo PRESENTE usa el nuevo
     * valor. Se construye el estado financiero RESULTANTE completo y se valida
     * ese estado entero antes de persistir.
     *
     * Si NO viene NINGUNO de los campos de crédito se devuelve [] (update
     * parcial conserva; create usa defaults de BD/modelo).
     *
     * Reglas de validación (sobre el estado resultante):
     *  - limite_credito >= 0 siempre.
     *  - dias_credito, cuando no es nulo, > 0.
     *  - Si credito_habilitado = true => limite > 0 y dias > 0 (redundante con
     *    la constraint BD, pero entregado como ValidationException amigable).
     */
    private function datosCredito(Request $request, ?Cliente $cliente = null): array
    {
        if (! CreditoAcceso::puedeConfigurar(Auth::user())) {
            return [];
        }

        if (! $request->has('credito_habilitado')
            && ! $request->has('limite_credito')
            && ! $request->has('dias_credito')) {
            return [];
        }

        $candidato = [
            'credito_habilitado' => $request->has('credito_habilitado') ? $request->input('credito_habilitado') : null,
            'limite_credito' => $this->inputFinancieroOpcional($request, 'limite_credito'),
            'dias_credito' => $this->inputFinancieroOpcional($request, 'dias_credito'),
        ];

        $reglas = [];
        if ($request->has('credito_habilitado')) {
            $reglas['credito_habilitado'] = ['required', 'boolean'];
        }
        if ($request->has('limite_credito')) {
            $reglas['limite_credito'] = ['nullable', 'numeric', 'min:0'];
        }
        if ($request->has('dias_credito')) {
            $reglas['dias_credito'] = ['nullable', 'integer', 'min:1'];
        }

        $validado = Validator::make($candidato, $reglas)->validate();

        $base = $cliente
            ? [
                'credito_habilitado' => (bool) $cliente->credito_habilitado,
                'limite_credito' => $cliente->limite_credito,
                'dias_credito' => $cliente->dias_credito,
            ]
            : [
                'credito_habilitado' => false,
                'limite_credito' => 0,
                'dias_credito' => null,
            ];

        if (array_key_exists('credito_habilitado', $validado)) {
            $base['credito_habilitado'] = $request->boolean('credito_habilitado');
        }
        if (array_key_exists('limite_credito', $validado)) {
            $base['limite_credito'] = $validado['limite_credito'];
        }
        if (array_key_exists('dias_credito', $validado)) {
            $base['dias_credito'] = $validado['dias_credito'];
        }

        $habilitado = $base['credito_habilitado'];
        $limite = $base['limite_credito'];
        $dias = $base['dias_credito'];

        if ($habilitado) {
            if ($limite === null || $limite <= 0) {
                throw ValidationException::withMessages([
                    'limite_credito' => 'Si el crédito está habilitado, el límite debe ser mayor a cero.',
                ]);
            }

            if ($dias === null || $dias <= 0) {
                throw ValidationException::withMessages([
                    'dias_credito' => 'Si el crédito está habilitado, los días de crédito deben ser mayor a cero.',
                ]);
            }
        }

        return [
            'credito_habilitado' => $habilitado,
            'limite_credito' => $limite === null ? '0' : (string) $limite,
            'dias_credito' => $dias,
        ];
    }

    /**
     * Campo financiero opcional: null si ausente o en blanco.
     */
    private function inputFinancieroOpcional(Request $request, string $campo): ?string
    {
        if (! $request->has($campo)) {
            return null;
        }

        $valor = $request->input($campo);

        return $valor === null || trim((string) $valor) === '' ? null : (string) $valor;
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
        $data = array_merge($this->normalizar($request), $this->datosCredito($request));
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
        $data = array_merge($this->normalizar($request), $this->datosCredito($request, $cliente));

        $cliente->update($data);

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
        $data = array_merge($this->normalizar($request), $this->datosCredito($request));
        $data['activo'] = true;

        $cliente = Cliente::create($data);

        session(['pos.cliente_id' => $cliente->id]);

        return redirect()->route('pos.index')
            ->with('success', "Cliente {$cliente->codigo} creado y seleccionado.");
    }
}
