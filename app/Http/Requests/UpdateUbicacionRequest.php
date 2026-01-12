<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUbicacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('ubicacion')?->id;

        return [
            'nombre' => ['required','string','max:120', Rule::unique('ubicaciones','nombre')->ignore($id)],
            'descripcion' => ['nullable','string','max:1000'],
            'activo' => ['nullable','boolean'],
        ];
    }
}
