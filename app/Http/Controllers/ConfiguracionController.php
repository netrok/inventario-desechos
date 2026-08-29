<?php

namespace App\Http\Controllers;

use App\Models\Configuracion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConfiguracionController extends Controller
{
    /**
     * Formulario de configuración (permiso configuracion.ver para ver, editar solo Admin).
     */
    public function edit()
    {
        $configuracion = Configuracion::obtener();

        return view('configuracion.edit', ['configuracion' => $configuracion]);
    }

    /**
     * Guarda la configuración (permiso configuracion.editar).
     * Solo datos de identidad/ticket; NUNCA secretos.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'empresa_nombre' => ['nullable', 'string', 'max:255'],
            'empresa_rfc' => ['nullable', 'string', 'max:20'],
            'empresa_telefono' => ['nullable', 'string', 'max:30'],
            'empresa_email' => ['nullable', 'email', 'max:255'],
            'empresa_direccion' => ['nullable', 'string', 'max:2000'],
            'ticket_pie' => ['nullable', 'string', 'max:500'],
            'ticket_ancho' => ['required', Rule::in(Configuracion::ANCHOS_VALIDOS)],
            'ticket_autoprint' => ['nullable', 'boolean'],
        ]);

        // Normaliza la entrada (los campos ausentes se tratan como null).
        $data['empresa_nombre'] = $request->filled('empresa_nombre') ? trim($request->input('empresa_nombre')) : null;
        $data['empresa_rfc'] = $request->filled('empresa_rfc') ? mb_strtoupper(trim($request->input('empresa_rfc'))) : null;
        $data['empresa_email'] = $request->filled('empresa_email') ? mb_strtolower(trim($request->input('empresa_email'))) : null;
        $data['ticket_autoprint'] = (bool) $request->boolean('ticket_autoprint');

        $configuracion = Configuracion::obtener();
        $configuracion->update($data);

        return redirect()
            ->route('configuracion.edit')
            ->with('success', 'Configuración actualizada.');
    }
}
