<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Привязка подписки</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f6f3ea;
            --card: #fffdf7;
            --text: #1f2430;
            --muted: #5e6472;
            --border: #d9d0c0;
            --accent: #0d6d64;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", sans-serif;
            background: radial-gradient(circle at top, #fff7df 0, var(--bg) 52%);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        .card {
            width: min(560px, 100%);
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 20px;
            padding: 28px;
            box-shadow: 0 18px 60px rgba(24, 29, 35, 0.08);
        }

        h1 {
            margin: 0 0 12px;
            font-size: 28px;
            line-height: 1.15;
        }

        p {
            margin: 0 0 14px;
            line-height: 1.5;
            color: var(--muted);
        }

        code {
            display: block;
            margin-top: 18px;
            padding: 16px;
            background: #f2eee3;
            border-radius: 12px;
            font-size: 14px;
            overflow-wrap: anywhere;
            color: var(--text);
        }

        .accent {
            color: var(--accent);
            font-weight: 600;
        }
    </style>
</head>
<body>
<main class="card">
    <h1>Привязка подписки</h1>
    <p>Откройте приложение WS VPN и выберите <span class="accent">«Привязать подписку»</span>.</p>
    @if($token !== '')
        <p>Если приложение не перехватило ссылку автоматически, вставьте этот код вручную:</p>
        <code>{{ $token }}</code>
    @else
        <p>В ссылке не найден код привязки. Вернитесь в кабинет и создайте новую ссылку.</p>
    @endif
</main>
</body>
</html>
