<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUbicacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // middleware permission manda
    }

    public function rules(): array
    {
        // Route model binding: {ubicacion}
        $ubicacion = $this->route('ubicacion');
        $id = is_object($ubicacion) ? $ubicacion->id : $ubicacion;

        return [
            'nombre' => [
                'required',
                'string',
                'max:120',
                Rule::unique('ubicaciones', 'nombre')->ignore($id),
                // Si luego agregas SoftDeletes a Ubicacion, cambia a:
                // Rule::unique('ubicaciones', 'nombre')->ignore($id)->whereNull('deleted_at'),
            ],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('activo')) {
            $this->merge([
                'activo' => $this->boolean('activo'),
            ]);
        }
    }
}
