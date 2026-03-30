<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Инструкция подключения</title>
    <style>
        body {
            margin: 0;
            padding: 16px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: #f5f7fb;
            color: #111827;
        }
        .card {
            max-width: 980px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 16px;
        }
    </style>
</head>
<body>
@php
    $protocol = trim((string) ($protocol ?? ''));
    $singleProtocol = in_array($protocol, ['amnezia_vpn', 'amneziawg', 'vless'], true) ? $protocol : '';
    $manualData = [
        'id' => $id,
        'vlessUrl' => $vlessUrl ?? '',
        'wireguardQrDataUri' => $wireguardQrDataUri ?? null,
        'awgQrDataUri' => $awgQrDataUri ?? null,
        'wireguardConfig' => $wireguardConfig ?? '',
        'amneziaWgConfig' => $amneziaWgConfig ?? '',
        'awgConfigUrl' => $awgConfigUrl ?? '',
        'amneziaWgConfigUrl' => $amneziaWgConfigUrl ?? '',
        'fileUrl' => $fileUrl ?? '',
    ];
@endphp
<div class="card">
    @if($singleProtocol !== '')
        {!! view('subscription.manual', array_merge($manualData, ['protocol' => $singleProtocol]))->render() !!}
    @else
        {!! view('subscription.manual_amnezia_vpn', $manualData)->render() !!}
        {!! view('subscription.manual_amneziawg', $manualData)->render() !!}
        @if(trim((string) ($manualData['vlessUrl'] ?? '')) !== '')
            {!! view('subscription.manual_vless', $manualData)->render() !!}
        @endif
    @endif
</div>

<script>
    function copyInstructionConfig(id) {
        const input = document.getElementById('instruction-config-' + id);
        if (!input) return;
        input.select();
        input.setSelectionRange(0, 999999);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).catch(function () {
                document.execCommand('copy');
            });
            return;
        }
        document.execCommand('copy');
    }

    function copyInstructionAwgConfig(id) {
        const input = document.getElementById('instruction-awg-config-' + id);
        copyInstructionTextareaElement(input);
    }

    function copyInstructionTextarea(id) {
        const input = document.getElementById(id);
        copyInstructionTextareaElement(input);
    }

    function copyInstructionTextareaElement(input) {
        if (!input) return;
        input.select();
        input.setSelectionRange(0, 999999);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(input.value).catch(function () {
                document.execCommand('copy');
            });
            return;
        }
        document.execCommand('copy');
    }

    function downloadInstructionAwgConfig(id) {
        const input = document.getElementById('instruction-awg-config-' + id);
        downloadInstructionTextareaElement(input, 'peer-1.conf');
    }

    function downloadInstructionTextarea(id, filename) {
        const input = document.getElementById(id);
        downloadInstructionTextareaElement(input, filename);
    }

    function downloadInstructionTextareaElement(input, filename) {
        if (!input) return;
        const blob = new Blob([input.value || ''], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename || 'config.txt';
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(function () {
            URL.revokeObjectURL(url);
        }, 0);
    }
</script>
</body>
</html>
