<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\Request;
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

        // El último Admin nunca puede perder el rol Admin (ni por democión).
        $rolesSent = $data['roles'] ?? [];
        if ($user->hasRole('Admin')
            && ! in_array('Admin', $rolesSent, true)
            && User::role('Admin')->count() <= 1) {
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
    }

    public function destroy(User $user)
    {
        // No te borres a ti mismo
        if (auth()->id() === $user->id) {
            return back()->with('error', 'No puedes eliminar tu propio usuario.');
        }

        // No borrar el último Admin
        if ($user->hasRole('Admin')) {
            $adminsCount = User::role('Admin')->count();
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
    }
}
