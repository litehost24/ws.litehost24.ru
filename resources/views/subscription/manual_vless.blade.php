@php
    $id = (string) ($id ?? '0');
    $vlessUrl = (string) ($vlessUrl ?? '');
@endphp

<div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.5; color: #111827; padding-bottom: 24px;">
    <h3 style="margin: 0 0 12px; font-size: 20px; font-weight: 800; color: #0f172a;">VLESS</h3>
    <div style="margin-bottom: 16px; color: #4b5563;">
        Резервный канал связи. Можно использовать и на ПК, и на телефоне.
    </div>

    <div style="margin-bottom: 10px;">
        <div style="font-weight: 600;">ПК (Windows)</div>
        <ol style="margin: 6px 0 0 18px; padding: 0;">
            <li>Скачайте программу <a href="/storage/files/setup-Happ.x64.exe">setup-Happ.x64.exe</a>.</li>
            <li>Скопируйте строку конфигурации ниже.</li>
            <li>Импортируйте настройки в приложении.</li>
        </ol>
    </div>

    <div style="margin-bottom: 16px;">
        <div style="font-weight: 600;">Телефон (Android)</div>
        <ol style="margin: 6px 0 0 18px; padding: 0;">
            <li>Установите приложение <strong>v2rayTun</strong> из Google Play Market.</li>
            <li>Скопируйте строку конфигурации ниже.</li>
            <li>Импортируйте настройки в приложении.</li>
        </ol>
    </div>

    <div style="margin: 0 0 8px; font-weight: 600;">Конфигурация</div>
    <div style="display:flex; gap:10px; align-items:flex-start; margin-bottom: 16px;">
        <textarea id="instruction-config-{{ $id }}" readonly rows="4" style="flex:1; padding: 10px 12px; background: #f3f4f6; border: 1px solid #e5e7eb; border-radius: 6px; word-break: break-all; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size: 12px;">{{ $vlessUrl }}</textarea>
        <div style="display:flex; flex-direction:column; gap:10px;">
            <button type="button" onclick="copyInstructionConfig({{ (int) $id }})" style="white-space:nowrap; padding: 10px 12px; border: 1px solid #e5e7eb; background: #fff; border-radius: 6px; font-weight: 600; cursor: pointer;">Копировать</button>
        </div>
    </div>
</div>
