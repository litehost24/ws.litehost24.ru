@php
    $id = (string) ($id ?? '0');
    $wireguardConfig = (string) ($wireguardConfig ?? '');
    $awgConfigUrl = (string) ($awgConfigUrl ?? '');
    $amneziaVpnInstallerUrl = '/storage/files/AmneziaVPN_4.8.14.5_x64.exe';
@endphp

<div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.5; color: #111827; padding-bottom: 24px;">
    <h3 style="margin: 0 0 12px; font-size: 20px; font-weight: 800; color: #0f172a;">AmneziaVPN</h3>
    <div style="margin-bottom: 16px;">
        <ol style="margin: 6px 0 0 18px; padding: 0; list-style: decimal;">
            <li>Установите приложение <strong>Amnezia VPN</strong> из Google Play Market.</li>
            <li>Импортируйте конфигурационный файл <a href="{{ $awgConfigUrl }}"><code>peer-1.conf</code></a> или отсканируйте QR-код ниже.</li>
            <li>Активируйте подключение.</li>
        </ol>
    </div>

    @if(!empty($wireguardConfig))
        <div style="margin: 0 0 8px; font-weight: 600;">Конфигурация</div>
        <div style="display:flex; gap:10px; align-items:flex-start; margin-bottom: 16px;">
            <textarea id="instruction-awg-config-{{ $id }}" readonly rows="10" style="flex:1; padding: 10px 12px; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 6px; word-break: break-word; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size: 12px;">{{ $wireguardConfig }}</textarea>
            <div style="display:flex; flex-direction:column; gap:10px;">
                <button type="button" onclick="copyInstructionAwgConfig({{ (int) $id }})" style="white-space:nowrap; padding: 10px 12px; border: 1px solid #e5e7eb; background: #fff; border-radius: 6px; font-weight: 600; cursor: pointer;">Копировать</button>
                @if(!empty($awgConfigUrl))
                    <a href="{{ $awgConfigUrl }}" style="text-align:center; white-space:nowrap; padding: 10px 12px; border: 1px solid #e5e7eb; background: #fff; border-radius: 6px; font-weight: 600; cursor: pointer; text-decoration: none; color: #111827;">Скачать .conf</a>
                @else
                    <button type="button" onclick="downloadInstructionAwgConfig({{ (int) $id }})" style="white-space:nowrap; padding: 10px 12px; border: 1px solid #e5e7eb; background: #fff; border-radius: 6px; font-weight: 600; cursor: pointer;">Скачать .conf</button>
                @endif
            </div>
        </div>
    @endif

    @if(!empty($wireguardQrDataUri))
        <div style="margin: 0 0 8px; font-weight: 600;">QR-код</div>
        <div style="margin-bottom: 16px;">
            <img
                src="{{ $wireguardQrDataUri }}"
                alt="AmneziaVPN QR"
                style="width: min(360px, 54vw); height: auto; border: 1px solid #e5e7eb; border-radius: 8px; padding: 6px; background: #fff; image-rendering: pixelated;"
            >
        </div>
    @endif

    <h3 style="margin: 6px 0 12px; font-size: 20px; font-weight: 800; color: #0f172a;">Windows</h3>
    <ol style="margin: 6px 0 0 18px; padding: 0;">
        <li>Скачайте и установите приложение <strong>Amnezia VPN</strong> для Windows: <a href="{{ $amneziaVpnInstallerUrl }}">AmneziaVPN_4.8.14.5_x64.exe</a>.</li>
        <li>Скачайте конфигурационный файл <a href="{{ $awgConfigUrl }}"><code>peer-1.conf</code></a>.</li>
        <li>Импортируйте его в приложение.</li>
        <li>Активируйте подключение.</li>
    </ol>
</div>
