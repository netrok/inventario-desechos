<?php

namespace App\Exports\Concerns;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * Fuerza que cualquier valor de tipo string se escriba en el XLSX como texto
 * literal (DataType::TYPE_STRING), nunca como número.
 *
 * Sin esto, PhpSpreadsheet detecta automáticamente los strings que "parecen"
 * número (ej. un número de serie de puros dígitos) y los guarda como celda
 * numérica con formato General. Excel entonces los muestra en notación
 * científica (1.23E+11) cuando la serie es larga, y además trunca cualquier
 * cero a la izquierda (ej. "00123" se vuelve "123"). Pasa igual con RFCs,
 * teléfonos, códigos, folios, etc. si algún día son solo dígitos.
 *
 * Uso: en el Export, implementar WithCustomValueBinder y usar este trait.
 *
 *   use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
 *   use App\Exports\Concerns\ForzarValoresComoTexto;
 *
 *   class MiExport implements ..., WithCustomValueBinder
 *   {
 *       use ForzarValoresComoTexto;
 *       ...
 *   }
 *
 * No afecta columnas que ya se mapean como int/float (ej. IDs) — esas se
 * siguen guardando como número normal, vía el binder por default.
 */
trait ForzarValoresComoTexto
{
    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return (new DefaultValueBinder())->bindValue($cell, $value);
    }
}
