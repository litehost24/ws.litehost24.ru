@php
    $id = (string) ($id ?? '0');
    $amneziaWgConfig = (string) ($amneziaWgConfig ?? '');
    $amneziaWgConfigUrl = (string) ($amneziaWgConfigUrl ?? '');
@endphp

<div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.5; color: #111827; padding-bottom: 24px;">
    <h3 style="margin: 0 0 12px; font-size: 20px; font-weight: 800; color: #0f172a;">AmneziaWG</h3>
    <div style="margin-bottom: 12px; color: #4b5563;">
        Используйте этот вариант, если AmneziaVPN недоступен. В первую очередь — для iPhone.
    </div>
    <div style="margin-bottom: 16px;">
        <ol style="margin: 6px 0 0 18px; padding: 0; list-style: decimal;">
            <li>Установите приложение <strong>AmneziaWG</strong>.</li>
            <li>Импортируйте конфигурационный файл <a href="{{ $amneziaWgConfigUrl }}"><code>peer-1.conf</code></a> или отсканируйте QR-код ниже.</li>
            <li>Активируйте подключение.</li>
        </ol>
    </div>

    @if(!empty($amneziaWgConfig))
        <div style="margin: 0 0 8px; font-weight: 600;">Конфигурация</div>
        <div style="display:flex; gap:10px; align-items:flex-start; margin-bottom: 16px;">
            <textarea id="instruction-amneziawg-config-{{ $id }}" readonly rows="10" style="flex:1; padding: 10px 12px; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 6px; word-break: break-word; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size: 12px;">{{ $amneziaWgConfig }}</textarea>
            <div style="display:flex; flex-direction:column; gap:10px;">
                <button type="button" onclick="copyInstructionTextarea('instruction-amneziawg-config-{{ $id }}')" style="white-space:nowrap; padding: 10px 12px; border: 1px solid #e5e7eb; background: #fff; border-radius: 6px; font-weight: 600; cursor: pointer;">Копировать</button>
                @if(!empty($amneziaWgConfigUrl))
                    <a href="{{ $amneziaWgConfigUrl }}" style="text-align:center; white-space:nowrap; padding: 10px 12px; border: 1px solid #e5e7eb; background: #fff; border-radius: 6px; font-weight: 600; cursor: pointer; text-decoration: none; color: #111827;">Скачать .conf</a>
                @else
                    <button type="button" onclick="downloadInstructionTextarea('instruction-amneziawg-config-{{ $id }}', 'peer-1-amneziawg.conf')" style="white-space:nowrap; padding: 10px 12px; border: 1px solid #e5e7eb; background: #fff; border-radius: 6px; font-weight: 600; cursor: pointer;">Скачать .conf</button>
                @endif
            </div>
        </div>
    @endif

    @if(!empty($awgQrDataUri))
        <div style="margin: 0 0 8px; font-weight: 600;">QR-код</div>
        <div style="margin-bottom: 16px;">
            <img
                src="{{ $awgQrDataUri }}"
                alt="AmneziaWG QR"
                style="width: min(360px, 54vw); height: auto; border: 1px solid #e5e7eb; border-radius: 8px; padding: 6px; background: #fff; image-rendering: pixelated;"
            >
        </div>
    @endif
</div>
