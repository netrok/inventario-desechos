<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Item;

class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $item = $this->route('item');                 // puede ser Item o id
        $id = $item instanceof Item ? $item->id : $item;

        return [
            // Si lo mandas, valida y asegura unique (pero no lo obligues)
            'codigo' => ['nullable', 'string', 'max:40', Rule::unique('items', 'codigo')->ignore($id)],

            'serie' => ['nullable', 'string', 'max:120'],
            'marca' => ['nullable', 'string', 'max:80'],
            'modelo' => ['nullable', 'string', 'max:120'],
            'categoria' => ['nullable', 'string', 'max:80'],

            'estado' => ['required', Rule::in(Item::ESTADOS)],
            'ubicacion_id' => ['nullable', 'exists:ubicaciones,id'],

            'notas' => ['nullable', 'string'],

            // ✅ Foto opcional en edición
            'foto' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }
}
