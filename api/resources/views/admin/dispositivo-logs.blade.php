<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs - {{ $dispositivo->codigo }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-gray-100 p-4">
    <div class="max-w-6xl mx-auto">
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-xl font-bold">{{ $dispositivo->codigo }} &mdash; logs</h1>
            <span id="status" class="text-sm text-green-400">auto-refresh: 5s</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm font-mono">
                <thead class="text-gray-400 border-b border-gray-700">
                    <tr>
                        <th class="text-left py-1 pr-4 w-48">created_at</th>
                        <th class="text-right py-1 pr-4 w-28">uptime_ms</th>
                        <th class="text-left py-1">mensaje</th>
                    </tr>
                </thead>
                <tbody id="logs">
                    @foreach($logs as $log)
                    <tr class="border-b border-gray-800 hover:bg-gray-800">
                        <td class="py-1 pr-4 text-gray-400 whitespace-nowrap">{{ $log->created_at->format('Y-m-d H:i:s.v') }}</td>
                        <td class="py-1 pr-4 text-right text-gray-500">{{ $log->uptime_ms }}</td>
                        <td class="py-1 whitespace-pre-wrap">{{ $log->mensaje }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($logs->isEmpty())
        <p class="text-gray-500 text-center mt-8">Sin logs</p>
        @endif
    </div>

    <script>
        setTimeout(() => location.reload(), 5000);
    </script>
</body>
</html>
