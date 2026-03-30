<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Amnezia VPN конфиг</title>
    <style>
        body {
            margin: 0;
            padding: 16px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: #f5f7fb;
            color: #111827;
        }
        .card {
            max-width: 900px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 16px;
        }
        h1 {
            margin: 0 0 12px;
            font-size: 20px;
        }
        p {
            margin: 0 0 12px;
            color: #4b5563;
        }
        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        button {
            border: 1px solid #d1d5db;
            background: #ffffff;
            border-radius: 8px;
            padding: 9px 12px;
            font-size: 14px;
            cursor: pointer;
        }
        button.primary {
            background: #111827;
            color: #fff;
            border-color: #111827;
        }
        textarea {
            width: 100%;
            min-height: 340px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            padding: 12px;
            resize: vertical;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
            font-size: 13px;
            line-height: 1.45;
            box-sizing: border-box;
        }
        .hint {
            margin-top: 12px;
            font-size: 13px;
            color: #374151;
        }
    </style>
</head>
<body>
<div class="card">
    <h1>Amnezia VPN конфиг</h1>
    <p>Нажмите «Скопировать», затем импортируйте в приложении Amnezia VPN.</p>

    @if (!empty($wireguardQrDataUri))
        <div style="margin: 12px 0 16px;">
            <div style="font-weight: 600; margin-bottom: 8px;">Amnezia VPN QR-код</div>
            <img src="{{ $wireguardQrDataUri }}" alt="Amnezia VPN QR" style="max-width: 280px; width: 100%; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; padding: 8px;">
        </div>
    @endif

    <div class="actions">
        <button id="copyBtn" class="primary" type="button">Скопировать</button>
        <button id="downloadBtn" type="button">Скачать .conf</button>
    </div>

    <textarea id="awgConfig" readonly>{{ $configText }}</textarea>
    <div class="hint">Файл: {{ $filename }}</div>
</div>

<script>
    (function () {
        const textArea = document.getElementById('awgConfig');
        const copyBtn = document.getElementById('copyBtn');
        const downloadBtn = document.getElementById('downloadBtn');
        const filename = @json($filename);

        copyBtn.addEventListener('click', async function () {
            const text = textArea.value || '';
            if (!text) return;
            try {
                await navigator.clipboard.writeText(text);
                copyBtn.textContent = 'Скопировано';
                setTimeout(() => copyBtn.textContent = 'Скопировать', 1500);
            } catch (e) {
                textArea.select();
                document.execCommand('copy');
                copyBtn.textContent = 'Скопировано';
                setTimeout(() => copyBtn.textContent = 'Скопировать', 1500);
            }
        });

        downloadBtn.addEventListener('click', function () {
            const blob = new Blob([textArea.value || ''], { type: 'text/plain;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename || 'peer-1.conf';
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        });
    })();
</script>
</body>
</html>
