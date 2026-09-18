<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Etiqueta {{ $item->codigo }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', system-ui, Roboto, sans-serif; background: #f3f4f6; color: #111827; }

        .no-print { background: #111827; }
        .no-print a, .no-print button {
            display: inline-flex; align-items: center;
            margin: 12px 8px 0 12px; padding: 8px 14px;
            border-radius: 8px; font-size: 14px; font-weight: 600;
            color: #f9fafb; background: #374151; text-decoration: none; border: none;
            cursor: pointer;
        }
        .no-print button { background: #059669; }
        .no-print button:hover { background: #047857; }

        .stage { display: flex; justify-content: center; margin-top: 24px; }

        #label {
            width: 50mm;
            height: 30mm;
            background: #ffffff;
            border: 0.6mm solid #000;
            display: flex;
            align-items: stretch;
            overflow: hidden;
        }

        .qr-side {
            width: 23mm;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5mm;
            border-right: 0.4mm dashed #374151;
        }

        .qr-side svg { display: block; width: 20mm; height: 20mm; }

        .info-side {
            flex: 1;
            min-width: 0; /* sin esto, un flex item nunca se encoge por debajo del
                             ancho de su contenido: el texto largo empuja el renglon
                             mas alla de los 50mm del label y #label (overflow:hidden)
                             lo corta en seco en vez de que cada linea haga elipsis. */
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 0.8mm;
            padding: 1.5mm 2mm;
        }

        .codigo {
            font-size: 9px;
            font-weight: 800;
            letter-spacing: 0px;
            line-height: 1.1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            width: 100%;
        }

        .line { font-size: 7.2px; color: #374151; line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; width: 100%; }

        @page { size: 50mm 30mm; margin: 0; }

        @media print {
            body { background: #ffffff; }
            .no-print { display: none !important; }
            .stage { margin-top: 0; }
            #label { border-width: 0.4mm; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button type="button" onclick="window.print()">Imprimir</button>
        <a href="{{ route('items.show', $item) }}">← Volver al ítem</a>
    </div>

    <div class="stage">
        <div id="label">
            <div class="qr-side">
                {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(90)->margin(4)->generate($item->codigo) !!}
            </div>

            <div class="info-side">
                <div class="codigo">{{ $item->codigo }}</div>
                @if($item->categoria)
                    <div class="line">{{ $item->categoria->nombre }}</div>
                @endif
                @if($item->marca)
                    <div class="line">{{ $item->marca }}{{ $item->modelo ? ' · '.$item->modelo : '' }}</div>
                @elseif($item->modelo)
                    <div class="line">{{ $item->modelo }}</div>
                @endif
                @if($item->serie)
                    <div class="line">Serie: {{ $item->serie }}</div>
                @endif
            </div>
        </div>
    </div>

    <script>
        // Esta pagina existe solo para imprimir la etiqueta -- abrir el
        // dialogo de impresion solo cuando ya cargo todo (fuentes/QR),
        // asi el usuario se ahorra el clic en "Imprimir".
        window.addEventListener('load', function () {
            window.print();
        });
    </script>
</body>
</html>