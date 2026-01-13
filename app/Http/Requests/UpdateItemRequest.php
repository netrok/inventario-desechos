<?php

namespace App\Http\Requests;

use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permisos ya en middleware
    }

    public function rules(): array
    {
        /** @var \App\Models\Item|mixed $item */
        $item = $this->route('item');
        $id = $item instanceof Item ? $item->id : $item;

        return [
            'codigo' => ['nullable', 'string', 'max:40', Rule::unique('items', 'codigo')->ignore($id)],

            'serie'  => ['nullable', 'string', 'max:120'],
            'marca'  => ['nullable', 'string', 'max:80'],
            'modelo' => ['nullable', 'string', 'max:120'],

            // ✅ Catálogo real
            'categoria_id' => ['required', 'integer', 'exists:categorias,id'],
            'ubicacion_id' => ['required', 'integer', 'exists:ubicaciones,id'],

            'estado' => ['required', Rule::in(Item::ESTADOS)],

            'notas' => ['nullable', 'string', 'max:1000'],

            // ✅ Foto opcional
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],

            // ✅ Checkbox para borrar foto actual
            'delete_foto' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'estado.in' => 'El estado seleccionado no es válido.',
            'categoria_id.required' => 'Selecciona una categoría.',
            'categoria_id.exists' => 'La categoría seleccionada no existe.',
            'ubicacion_id.required' => 'Selecciona una ubicación.',
            'ubicacion_id.exists' => 'La ubicación seleccionada no existe.',
            'foto.image' => 'El archivo debe ser una imagen.',
        ];
    }
}
