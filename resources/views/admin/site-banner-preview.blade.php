<!doctype html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Предпросмотр письма</title>
</head>
<body style="font-family: Arial, sans-serif; color: #111827; line-height: 1.5; background: #f9fafb; margin: 0; padding: 24px;">
    <div style="max-width: 720px; margin: 0 auto;">
        <div style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
            <div style="font-size: 14px; color: #6b7280;">Тема письма</div>
            <div style="font-size: 18px; font-weight: 600; margin-top: 4px;">{{ $subject }}</div>
            @if(!empty($attachArchives))
                <div style="margin-top: 8px; font-size: 12px; color: #065f46; background: #d1fae5; display: inline-block; padding: 4px 8px; border-radius: 9999px;">
                    Вложение: архив настроек
                </div>
            @endif
        </div>
        <div style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px;">
            {!! $emailHtml !!}
        </div>
    </div>
</body>
</html>
