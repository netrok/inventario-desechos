<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUbicacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // controlas por middleware permission
    }

    public function rules(): array
    {
        return [
            'nombre' => [
                'required',
                'string',
                'max:120',
                Rule::unique('ubicaciones', 'nombre'),
                // Si luego agregas SoftDeletes a Ubicacion, cambia a:
                // Rule::unique('ubicaciones', 'nombre')->whereNull('deleted_at'),
            ],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'activo' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // checkbox: si viene "on"/"1" => true, si no viene => false (en controller decides si guardarlo)
        if ($this->has('activo')) {
            $this->merge([
                'activo' => $this->boolean('activo'),
            ]);
        }
    }
}
