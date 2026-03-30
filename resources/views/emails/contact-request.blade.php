<div style="font-family: Arial, sans-serif; line-height: 1.4;">
    <h2 style="margin: 0 0 12px;">Сообщение с сайта</h2>

    <p style="margin: 0 0 8px;">
        <strong>Имя:</strong> {{ $from_name ?? '' }}<br>
        <strong>Email:</strong> {{ $from_email ?? '' }}
    </p>

    <p style="margin: 0 0 8px;"><strong>Сообщение:</strong></p>
    <pre style="white-space: pre-wrap; background: #f6f8fa; padding: 12px; border-radius: 6px; margin: 0 0 12px;">{{ $body ?? '' }}</pre>

    @if (!empty($meta))
        <p style="margin: 0 0 8px;"><strong>Тех. данные:</strong></p>
        <pre style="white-space: pre-wrap; background: #f6f8fa; padding: 12px; border-radius: 6px; margin: 0;">{{ print_r($meta, true) }}</pre>
    @endif
</div>
