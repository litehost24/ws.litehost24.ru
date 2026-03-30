@php
    $wireguardConfig = (string) ($wireguardConfig ?? '');
    $awgQrDataUri = (string) ($awgQrDataUri ?? '');
    $appBaseUrl = rtrim((string) config('app.url'), '/');
    $amneziaInstallerUrl = $appBaseUrl . '/storage/files/AmneziaVPN_4.8.14.5_x64.exe';
@endphp

<div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.5; color: #111827; padding-bottom: 48px;">
    <h3 style="margin: 0 0 12px; font-size: 20px; font-weight: 800; color: #0f172a;">Для мобильного устройства (Android / iOS)</h3>
    <div style="margin-bottom: 16px;">
        <ol style="margin: 6px 0 0 18px; padding: 0; list-style: decimal;">
            <li>Установите приложение <strong>Amnezia VPN</strong> из Google Play Market / App Store.</li>
            <li>Импортируйте конфигурационный файл <code>peer-1.conf</code> из архива или отсканируйте QR-код ниже.</li>
            <li>Если при сканировании QR появляются проблемы, скопируйте код настройки из поля ниже и вставьте в настройках приложения.</li>
            <li>Готово! Активируйте подключение.</li>
        </ol>
    </div>

    @if(!empty($wireguardConfig))
        <div style="margin: 0 0 8px; font-weight: 600;">2. AmneziaVPN конфигурация</div>
        <div style="display:flex; gap:10px; align-items:flex-start; margin-bottom: 16px;">
            <textarea id="lh-awg" readonly rows="10" style="flex:1; padding: 10px 12px; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 6px; word-break: break-word; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size: 12px;">{{ $wireguardConfig }}</textarea>
            <button type="button" onclick="lhCopy('lh-awg')" style="white-space:nowrap; padding: 10px 12px; border: 1px solid #e5e7eb; background: #fff; border-radius: 6px; font-weight: 600; cursor: pointer;">Копировать</button>
        </div>
    @endif

    @if(!empty($wireguardQrDataUri))
        <div style="margin: 0 0 8px; font-weight: 600;">3. AmneziaVPN QR-код</div>
        <div style="margin-bottom: 16px;">
            <img
                src="{{ $wireguardQrDataUri }}"
                alt="AmneziaWG QR"
                style="width: min(360px, 54vw); height: auto; border: 1px solid #e5e7eb; border-radius: 8px; padding: 6px; background: #fff; image-rendering: pixelated;"
            >
        </div>
    @endif

    @if(!empty($awgQrDataUri))
        <div style="margin: 0 0 8px; font-weight: 600;">4. AmneziaWG QR-код</div>
        <div style="margin: 0 0 8px; color: #4b5563;">Для отдельного приложения <strong>AmneziaWG</strong> используйте этот QR-код или файл <code>peer-1-amneziawg.conf</code> из архива.</div>
        <div style="margin-bottom: 12px;">
            <a href="peer-1-amneziawg.conf" download style="display:inline-block; text-align:center; white-space:nowrap; padding: 8px 12px; border: 1px solid #e5e7eb; background: #fff; border-radius: 6px; font-weight: 600; cursor: pointer; text-decoration: none; color: #111827;">Скачать AmneziaWG .conf</a>
        </div>
        <div style="margin-bottom: 16px;">
            <img
                src="{{ $awgQrDataUri }}"
                alt="AmneziaWG QR"
                style="width: min(360px, 54vw); height: auto; border: 1px solid #e5e7eb; border-radius: 8px; padding: 6px; background: #fff; image-rendering: pixelated;"
            >
        </div>
    @endif

    <h3 style="margin: 6px 0 12px; font-size: 20px; font-weight: 800; color: #0f172a;">5. Для компьютера (Windows)</h3>
    <div style="margin-bottom: 16px;">
        <div style="font-weight: 600;">Amnezia VPN</div>
        <ol style="margin: 6px 0 0 18px; padding: 0;">
            <li>Скачайте и установите приложение <strong>Amnezia VPN</strong> для Windows: <a href="{{ $amneziaInstallerUrl }}">{{ $amneziaInstallerUrl }}</a>.</li>
            <li>
                Скачайте конфигурационный файл <code>peer-1.conf</code>.
                @if(!empty($wireguardConfig))
                    <a href="peer-1.conf" download style="display:inline-block; margin-left:6px; text-align:center; white-space:nowrap; padding: 6px 10px; border: 1px solid #e5e7eb; background: #fff; border-radius: 6px; font-weight: 600; cursor: pointer; text-decoration: none; color: #111827;">Скачать .conf</a>
                @endif
            </li>
            <li>Откройте Amnezia VPN и импортируйте файл <code>peer-1.conf</code>.</li>
            <li>Активируйте подключение.</li>
        </ol>
    </div>

</div>

<script>
    function lhCopy(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.focus();
        el.select();
        el.setSelectionRange(0, 999999);

        var text = el.value || '';
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).catch(function () {
                document.execCommand('copy');
            });
        } else {
            document.execCommand('copy');
        }
    }
</script>
