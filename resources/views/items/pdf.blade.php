<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de Items</title>

    <style>
        @page { margin: 18px 18px 34px 18px; }

        body{
            font-family: DejaVu Sans, sans-serif;
            font-size: 10.5px;
            color: #111827;
        }

        .title { font-size: 16px; font-weight: 700; margin: 0; }
        .meta  { margin: 4px 0 0 0; color:#6B7280; font-size: 9px; }

        /* Chips de filtros */
        .filters-box{
            margin: 8px 0 10px 0;
            padding: 6px 8px;
            border: 1px solid #E5E7EB;
            background: #F9FAFB;
            border-radius: 8px;
            font-size: 9px;
            color: #374151;
        }
        .filters-title{
            font-weight: 700;
            margin-right: 6px;
            color:#111827;
        }
        .chip{
            display: inline-block;
            padding: 2px 8px;
            margin: 3px 4px 0 0;
            border: 1px solid #E5E7EB;
            background: #FFFFFF;
            border-radius: 999px;
            line-height: 1.4;
        }
        .chip strong{ font-weight: 700; }

        .hr { border-top: 1px solid #E5E7EB; margin: 10px 0 12px 0; }

        table{
            width:100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        thead{ display: table-header-group; }
        tr{ page-break-inside: avoid; }

        th, td{
            border: 1px solid #E5E7EB;
            padding: 6px;
            vertical-align: top;
            word-wrap: break-word;
        }
        th{
            background:#F3F4F6;
            font-size: 9px;
            text-transform: uppercase;
            color:#374151;
        }

        /* Column widths */
        .col-foto   { width: 54px; }
        .col-id     { width: 34px; }
        .col-codigo { width: 86px; }
        .col-estado { width: 72px; }
        .col-notas  { width: 130px; }

        /* Thumb */
        .thumb-wrap{
            width:42px;
            height:42px;
            border:1px solid #E5E7EB;
            border-radius:6px;
            overflow:hidden;
            background:#F9FAFB;
            text-align:center;
            line-height:42px;
        }
        .thumb{
            width:42px;
            height:42px;
            display:block;
        }
        .no-photo{
            font-size:8px;
            color:#9CA3AF;
        }

        /* Badge */
        .badge{
            display:inline-block;
            padding: 2px 6px;
            border: 1px solid #D1D5DB;
            border-radius: 4px;
            font-size: 9px;
            font-weight: 700;
        }
        .b-ok   { background:#ECFDF5; border-color:#A7F3D0; color:#047857; }
        .b-warn { background:#FFFBEB; border-color:#FDE68A; color:#B45309; }
        .b-info { background:#EFF6FF; border-color:#BFDBFE; color:#1D4ED8; }
        .b-gray { background:#F3F4F6; border-color:#E5E7EB; color:#374151; }
        .b-bad  { background:#FEF2F2; border-color:#FECACA; color:#B91C1C; }

        /* Footer fijo */
        .footer{
            position: fixed;
            bottom: -10px;
            left: 0;
            right: 0;
            font-size: 9px;
            color:#6B7280;
        }
        .footer-left{ position:absolute; left:18px; }
        .footer-right{ position:absolute; right:18px; }
    </style>
</head>

<body>
@php
    $badgeClass = function ($estado) {
        return match($estado) {
            'DISPONIBLE' => 'b-ok',
            'RESERVADO'  => 'b-warn',
            'REPARACION', 'REPARACIÓN' => 'b-info',
            'VENDIDO'    => 'b-gray',
            'BAJA'       => 'b-bad',
            default      => 'b-gray',
        };
    };

    // DomPDF-friendly: base64 inline
    $imgBase64 = function ($fotoPath) {
        if (empty($fotoPath)) return null;

        $full = public_path('storage/' . ltrim($fotoPath, '/'));
        if (!is_file($full)) return null;

        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => null,
        };
        if (!$mime) return null;

        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($full));
    };
@endphp

    <h1 class="title">Reporte de Items</h1>
    <p class="meta">
        Generado: {{ optional($generatedAt)->format('Y-m-d H:i') }}
        &nbsp;•&nbsp;
        Total: {{ $items->count() }}
    </p>

    <div class="filters-box">
        <span class="filters-title">Filtros</span>

        <span class="chip">
            Buscar: <strong>{{ !empty($filters['q']) ? $filters['q'] : 'Todos' }}</strong>
        </span>

        <span class="chip">
            Estado: <strong>{{ !empty($filters['estado']) ? $filters['estado'] : 'Todos' }}</strong>
        </span>

        <span class="chip">
            Ubicación: <strong>{{ $filters['ubicacion_name'] ?? 'Todas' }}</strong>
        </span>

        <span class="chip">
            Categoría: <strong>{{ $filters['categoria_name'] ?? 'Todas' }}</strong>
        </span>
    </div>

    <div class="hr"></div>

    <table>
        <thead>
            <tr>
                <th class="col-foto">Foto</th>
                <th class="col-id">ID</th>
                <th class="col-codigo">Código</th>
                <th>Serie</th>
                <th>Marca</th>
                <th>Modelo</th>
                <th>Categoría</th>
                <th>Ubicación</th>
                <th class="col-estado">Estado</th>
                <th class="col-notas">Notas</th>
            </tr>
        </thead>

        <tbody>
        @foreach($items as $it)
            @php
                $src = $imgBase64($it->foto_path ?? null);
                $estado = $it->estado ?? '—';
            @endphp

            <tr>
                <td class="col-foto">
                    <div class="thumb-wrap">
                        @if($src)
                            <img class="thumb" src="{{ $src }}" alt="foto">
                        @else
                            <span class="no-photo">Sin foto</span>
                        @endif
                    </div>
                </td>

                <td class="col-id">{{ $it->id }}</td>
                <td class="col-codigo"><strong>{{ $it->codigo }}</strong></td>
                <td>{{ $it->serie ?: '—' }}</td>
                <td>{{ $it->marca ?: '—' }}</td>
                <td>{{ $it->modelo ?: '—' }}</td>
                <td>{{ $it->categoria?->nombre ?? '—' }}</td>
                <td>{{ $it->ubicacion?->nombre ?? '—' }}</td>
                <td class="col-estado">
                    <span class="badge {{ $badgeClass($estado) }}">{{ $estado }}</span>
                </td>
                <td class="col-notas">{{ $it->notas ?: '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="footer">
        <div class="footer-left">Inventario Desechos · Reporte de Items</div>
        <div class="footer-right">Página {PAGE_NUM} de {PAGE_COUNT}</div>
    </div>

</body>
</html>
