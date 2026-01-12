<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreUbicacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // ya controlas por permisos en middleware
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required','string','max:120','unique:ubicaciones,nombre'],
            'descripcion' => ['nullable','string','max:1000'],
            'activo' => ['nullable','boolean'],
        ];
    }
}
