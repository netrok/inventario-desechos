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
            width: 26mm;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1mm;
            padding: 1.5mm;
            border-right: 0.4mm dashed #374151;
        }

        .qr-side svg { display: block; width: 22mm; height: 22mm; }

        .qr-hint { font-size: 7px; color: #4b5563; text-align: center; letter-spacing: 0.5px; }

        .info-side {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 1mm;
            padding: 1.5mm 2mm;
        }

        .codigo {
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 0.5px;
            line-height: 1.1;
            white-space: nowrap;
        }

        .line { font-size: 9px; color: #374151; line-height: 1.25; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

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
                {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::size(90)->generate($item->codigo) !!}
                <div class="qr-hint">Escanea</div>
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
</body>
</html>