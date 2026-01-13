@csrf

<div class="grid gap-4">
    <div>
        <label class="block text-sm font-medium">Nombre</label>
        <input name="name" value="{{ old('name', $user->name ?? '') }}"
               class="w-full rounded-md border-gray-300" />
        @error('name') <div class="text-red-600 text-sm">{{ $message }}</div> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium">Email</label>
        <input name="email" type="email" value="{{ old('email', $user->email ?? '') }}"
               class="w-full rounded-md border-gray-300" />
        @error('email') <div class="text-red-600 text-sm">{{ $message }}</div> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium">Password {{ isset($user) ? '(solo si deseas cambiarlo)' : '' }}</label>
        <input name="password" type="password" class="w-full rounded-md border-gray-300" />
        @error('password') <div class="text-red-600 text-sm">{{ $message }}</div> @enderror
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">Roles</label>
        <div class="flex flex-wrap gap-3">
            @php
                $selected = old('roles', isset($user) ? $user->roles->pluck('name')->all() : []);
            @endphp

            @foreach ($roles as $role)
                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" name="roles[]"
                           value="{{ $role->name }}"
                           @checked(in_array($role->name, $selected, true)) />
                    <span>{{ $role->name }}</span>
                </label>
            @endforeach
        </div>
        @error('roles') <div class="text-red-600 text-sm">{{ $message }}</div> @enderror
        @error('roles.*') <div class="text-red-600 text-sm">{{ $message }}</div> @enderror
    </div>
</div>
