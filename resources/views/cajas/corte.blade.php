<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Corte de caja {{ $d['folio'] }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        @media print {
            .no-print {
                display: none !important;
            }

            body {
                background: white !important;
            }

            .print-container {
                max-width: none !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            .print-card {
                box-shadow: none !important;
                break-inside: avoid;
            }
        }
    </style>
</head>

<body class="bg-gray-100 text-gray-900">

@if(! $modoImpresion)
    <div class="no-print border-b border-gray-200 bg-white">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-4 sm:px-6">
            <div>
                <h1 class="text-lg font-bold">
                    Corte de caja {{ $d['folio'] }}
                </h1>
                <p class="text-sm text-gray-500">
                    {{ $d['caja_codigo'] }} · {{ $d['caja_nombre'] }}
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a
                    href="{{ route('cajas.corte.imprimir', $sesion) }}"
                    target="_blank"
                    class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black"
                >
                    Imprimir
                </a>

                <a
                    href="{{ route('cajas.corte.pdf', $sesion) }}"
                    class="rounded-lg bg-rose-700 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-800"
                >
                    PDF
                </a>

                <a
                    href="{{ route('cajas.corte.xlsx', $sesion) }}"
                    class="rounded-lg border border-emerald-700 bg-white px-4 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50"
                >
                    XLSX
                </a>

                <a
                    href="{{ route('cajas.index') }}"
                    class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                >
                    Volver a Caja
                </a>
            </div>
        </div>
    </div>
@endif

<main class="print-container mx-auto max-w-6xl space-y-5 px-4 py-6 sm:px-6">

    <div class="print-card rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-5">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-400">
                    Corte de caja
                </p>
                <h2 class="mt-1 text-2xl font-bold">
                    {{ $d['folio'] }}
                </h2>
                <p class="mt-1 text-sm text-gray-500">
                    {{ $d['caja_codigo'] }} — {{ $d['caja_nombre'] }}
                </p>
            </div>

            <div class="text-right text-sm">
                <p>
                    <span class="text-gray-500">Apertura:</span>
                    <strong>{{ $d['apertura']?->format('d/m/Y H:i:s') ?? '—' }}</strong>
                </p>
                <p class="mt-1">
                    <span class="text-gray-500">Cierre:</span>
                    <strong>{{ $d['cierre']?->format('d/m/Y H:i:s') ?? '—' }}</strong>
                </p>
            </div>
        </div>
    </div>

    <div class="grid gap-5 md:grid-cols-2">
        <section class="print-card rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="mb-4 text-sm font-bold uppercase tracking-wide text-gray-600">
                Identificación
            </h3>

            <dl class="space-y-3 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Caja</dt>
                    <dd class="font-semibold text-right">
                        {{ $d['caja_codigo'] }} — {{ $d['caja_nombre'] }}
                    </dd>
                </div>

                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Operador</dt>
                    <dd class="font-semibold text-right">
                        {{ $d['operador'] }}
                    </dd>
                </div>

                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Cerrado por</dt>
                    <dd class="font-semibold text-right">
                        {{ $d['cerrado_por'] ?? '—' }}
                    </dd>
                </div>

                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500">Fondo inicial</dt>
                    <dd class="font-bold">
                        ${{ $d['fondo_inicial'] }}
                    </dd>
                </div>
            </dl>
        </section>

        <section class="print-card rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="mb-4 text-sm font-bold uppercase tracking-wide text-gray-600">
                Ventas y pagos
            </h3>

            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Ventas totales</dt>
                    <dd class="font-bold">${{ $d['ventas_totales'] }}</dd>
                </div>

                <div class="flex justify-between">
                    <dt class="text-gray-500">Efectivo</dt>
                    <dd class="font-semibold">${{ $d['pagos_por_metodo']['EFECTIVO'] }}</dd>
                </div>

                <div class="flex justify-between">
                    <dt class="text-gray-500">Tarjeta</dt>
                    <dd class="font-semibold">${{ $d['pagos_por_metodo']['TARJETA'] }}</dd>
                </div>

                <div class="flex justify-between">
                    <dt class="text-gray-500">Transferencia</dt>
                    <dd class="font-semibold">${{ $d['pagos_por_metodo']['TRANSFERENCIA'] }}</dd>
                </div>
            </dl>
        </section>
    </div>

    <div class="grid gap-5 md:grid-cols-2">
        <section class="print-card rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="mb-4 text-sm font-bold uppercase tracking-wide text-gray-600">
                Efectivo
            </h3>

            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Recibido bruto</dt>
                    <dd class="font-semibold">${{ $d['efectivo_recibido_bruto'] }}</dd>
                </div>

                <div class="flex justify-between">
                    <dt class="text-gray-500">Cambio entregado</dt>
                    <dd class="font-semibold text-rose-700">
                        -${{ $d['cambio_entregado'] }}
                    </dd>
                </div>

                <div class="flex justify-between border-t border-gray-100 pt-3">
                    <dt class="font-semibold">Efectivo neto aplicado</dt>
                    <dd class="font-bold text-emerald-700">
                        ${{ $d['efectivo_neto'] }}
                    </dd>
                </div>
            </dl>
        </section>

        <section class="print-card rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
            <h3 class="mb-4 text-sm font-bold uppercase tracking-wide text-gray-600">
                Operaciones de caja
            </h3>

            <dl class="space-y-3 text-sm">
                <div class="flex justify-between">
                    <dt class="text-gray-500">Entradas manuales</dt>
                    <dd class="font-semibold text-emerald-700">
                        +${{ $d['entradas_manuales'] }}
                    </dd>
                </div>

                <div class="flex justify-between">
                    <dt class="text-gray-500">Retiros</dt>
                    <dd class="font-semibold text-rose-700">
                        -${{ $d['retiros'] }}
                    </dd>
                </div>

                <div class="flex justify-between">
                    <dt class="text-gray-500">Reembolsos efectivo</dt>
                    <dd class="font-semibold text-rose-700">
                        -${{ $d['reembolsos'] }}
                    </dd>
                </div>

                <div class="flex justify-between">
                    <dt class="text-gray-500">Ajustes entrada</dt>
                    <dd class="font-semibold text-emerald-700">
                        +${{ $d['ajustes_entrada'] }}
                    </dd>
                </div>

                <div class="flex justify-between">
                    <dt class="text-gray-500">Ajustes salida</dt>
                    <dd class="font-semibold text-rose-700">
                        -${{ $d['ajustes_salida'] }}
                    </dd>
                </div>
            </dl>
        </section>
    </div>

    <section class="print-card rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        <h3 class="mb-5 text-sm font-bold uppercase tracking-wide text-gray-600">
            Arqueo y cierre
        </h3>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="rounded-xl bg-gray-50 p-4">
                <p class="text-xs font-semibold uppercase text-gray-400">
                    Esperado
                </p>
                <p class="mt-1 text-2xl font-bold">
                    ${{ $d['esperado'] }}
                </p>
            </div>

            <div class="rounded-xl bg-gray-50 p-4">
                <p class="text-xs font-semibold uppercase text-gray-400">
                    Contado
                </p>
                <p class="mt-1 text-2xl font-bold">
                    ${{ $d['contado'] ?? '—' }}
                </p>
            </div>

            <div class="rounded-xl p-4
                {{ (float) ($d['diferencia'] ?? 0) == 0
                    ? 'bg-emerald-50'
                    : 'bg-rose-50' }}">
                <p class="text-xs font-semibold uppercase text-gray-400">
                    Diferencia
                </p>

                <p class="mt-1 text-2xl font-bold
                    {{ (float) ($d['diferencia'] ?? 0) == 0
                        ? 'text-emerald-700'
                        : 'text-rose-700' }}">
                    ${{ $d['diferencia'] ?? '—' }}
                </p>
            </div>
        </div>

        @if($d['observaciones_cierre'])
            <div class="mt-5 rounded-xl border border-gray-200 bg-gray-50 p-4 text-sm">
                <span class="font-semibold">Observaciones:</span>
                {{ $d['observaciones_cierre'] }}
            </div>
        @endif
    </section>

    @if(! empty($d['denominaciones']))
        <section class="print-card overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-5 py-4">
                <h3 class="text-sm font-bold uppercase tracking-wide text-gray-600">
                    Arqueo por denominaciones
                </h3>
            </div>

            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-5 py-3 text-left">Denominación</th>
                        <th class="px-5 py-3 text-right">Cantidad</th>
                        <th class="px-5 py-3 text-right">Subtotal</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100">
                    @foreach($d['denominaciones'] as $den)
                        <tr>
                            <td class="px-5 py-3 font-semibold">
                                ${{ $den->denominacion }}
                            </td>
                            <td class="px-5 py-3 text-right">
                                {{ $den->cantidad }}
                            </td>
                            <td class="px-5 py-3 text-right font-semibold">
                                ${{ $den->subtotal }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <div class="pb-6 text-center text-xs text-gray-400">
        Inventario ReUse · Corte de caja {{ $d['folio'] }}
    </div>
</main>

@if($modoImpresion)
    <script>
        window.addEventListener('load', () => {
            window.print();
        });
    </script>
@endif

</body>
</html>
