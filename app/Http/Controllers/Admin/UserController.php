<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($qq) use ($q) {
                    $qq->where('name', 'ilike', "%{$q}%")
                        ->orWhere('email', 'ilike', "%{$q}%");
                });
            })
            ->with('roles')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.users.index', [
            'users' => $users,
            'q' => $q,
        ]);
    }

    public function create()
    {
        return view('admin.users.create', [
            'roles' => Role::query()->orderBy('name')->get(),
        ]);
    }

    public function store(StoreUserRequest $request)
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $user->syncRoles($data['roles'] ?? []);

        return redirect()
            ->route('admin.users.index')
            ->with('success', 'Usuario creado.');
    }

    public function edit(User $user)
    {
        return view('admin.users.edit', [
            'user' => $user->load('roles'),
            'roles' => Role::query()->orderBy('name')->get(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user)
    {
        $data = $request->validated();
        $rolesSent = $data['roles'] ?? [];

        // Todo el bloque vive dentro de una transacción con lockForUpdate:
        // mientras esta transacción no termine (commit/rollback), cualquier
        // otra petición concurrente que también intente leer el conteo de
        // Admins vía lockForUpdate() se queda ESPERANDO aquí, en vez de leer
        // un conteo desactualizado en paralelo. Mismo patrón que ya usa
        // PostventaService/PosController para evitar condiciones de carrera.
        return DB::transaction(function () use ($user, $data, $rolesSent) {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());

            // El último Admin nunca puede perder el rol Admin (ni por democión).
            $adminsRestantes = User::query()->role('Admin')->lockForUpdate()->get()->count();

            if ($user->hasRole('Admin')
                && ! in_array('Admin', $rolesSent, true)
                && $adminsRestantes <= 1) {
                return back()
                    ->with('error', 'No puedes quitar el rol Admin al último administrador.')
                    ->withInput();
            }

            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
            ]);

            if (! empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }

            $user->save();
            $user->syncRoles($rolesSent);

            return redirect()
                ->route('admin.users.index')
                ->with('success', 'Usuario actualizado.');
        });
    }

    public function destroy(User $user)
    {
        // No te borres a ti mismo
        if (auth()->id() === $user->id) {
            return back()->with('error', 'No puedes eliminar tu propio usuario.');
        }

        return DB::transaction(function () use ($user) {
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());

            // No borrar el último Admin (bajo lock: ver comentario en update()).
            if ($user->hasRole('Admin')) {
                $adminsCount = User::query()->role('Admin')->lockForUpdate()->get()->count();
                if ($adminsCount <= 1) {
                    return back()->with('error', 'No puedes eliminar al último Admin.');
                }
            }

            // No borrar un usuario con ventas: perdería el actor histórico.
            if ($user->ventas()->exists()) {
                return back()->with('error', 'No se puede eliminar este usuario porque tiene ventas registradas.');
            }

            // No borrar un usuario con documentos postventa: perdería el actor de
            // cancelaciones/devoluciones. Defensa EXPLÍCITA (no depender solo de que
            // toda postventa genere obligatoriamente un Movimiento con el mismo actor).
            if ($user->documentosPostventa()->exists()) {
                return back()->with('error', 'No se puede eliminar este usuario porque tiene documentos postventa registrados.');
            }

            // No borrar un usuario con movimientos: perdería el actor histórico.
            if ($user->movimientos()->exists()) {
                return back()->with('error', 'No se puede eliminar este usuario porque tiene movimientos registrados.');
            }

            $user->delete();

            return redirect()
                ->route('admin.users.index')
                ->with('success', 'Usuario eliminado.');
        });
    }
}
