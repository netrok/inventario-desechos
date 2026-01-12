<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Item;

class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Se genera automático; si viene, que sea válido y único
            'codigo' => ['nullable', 'string', 'max:40', 'unique:items,codigo'],

            'serie' => ['nullable', 'string', 'max:120'],
            'marca' => ['nullable', 'string', 'max:80'],
            'modelo' => ['nullable', 'string', 'max:120'],
            'categoria' => ['nullable', 'string', 'max:80'],

            'estado' => ['required', Rule::in(Item::ESTADOS)],
            'ubicacion_id' => ['nullable', 'exists:ubicaciones,id'],

            'notas' => ['nullable', 'string'],

            // ✅ Foto (no se guarda en BD, solo se sube y guardas foto_path)
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }
}
