<?php

namespace App\Http\Requests;

use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ya controlas por permisos en middleware
    }

    public function rules(): array
    {
        return [
            // Si lo mandas manual (raro), que sea válido y único.
            // Si no lo mandas, el modelo lo genera.
            'codigo' => ['nullable', 'string', 'max:40', 'unique:items,codigo'],

            'serie'  => ['nullable', 'string', 'max:120'],
            'marca'  => ['nullable', 'string', 'max:80'],
            'modelo' => ['nullable', 'string', 'max:120'],

            // Legacy temporal (si todavía existe en tu UI). Si ya migraste 100% a categoria_id, puedes borrar esto.
            'categoria' => ['nullable', 'string', 'max:80'],

            // ✅ Catálogo real
            'categoria_id' => ['nullable', 'integer', 'exists:categorias,id'],
            'ubicacion_id' => ['nullable', 'integer', 'exists:ubicaciones,id'],

            'estado' => ['required', Rule::in(Item::ESTADOS)],

            'notas' => ['nullable', 'string', 'max:1000'],

            // ✅ Foto (se sube; tú guardas foto_path en el controller)
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'estado.in' => 'El estado seleccionado no es válido.',
            'categoria_id.exists' => 'La categoría seleccionada no existe.',
            'ubicacion_id.exists' => 'La ubicación seleccionada no existe.',
            'foto.image' => 'El archivo debe ser una imagen.',
        ];
    }
}
