<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Create Your Store' }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; background: #f4f5f7; color: #1f2430; }
        main { padding: 1.5rem; max-width: 480px; margin: 3rem auto; }
        .card { background: #fff; border-radius: 6px; padding: 1.5rem; box-shadow: 0 1px 2px rgba(0,0,0,0.06); }
        h1 { font-size: 1.3rem; margin-top: 0; }
        label { display: block; margin-bottom: 0.25rem; font-weight: 600; }
        input { width: 100%; box-sizing: border-box; padding: 0.5rem; margin-bottom: 1rem; border: 1px solid #d3d6dd; border-radius: 4px; }
        button { cursor: pointer; background: #1f2430; color: #fff; border: none; padding: 0.6rem 1.2rem; border-radius: 4px; }
        .hint { color: #6b7280; font-size: 0.85rem; margin-top: -0.5rem; margin-bottom: 1rem; }
        .errors { background: #fbdada; color: #8f1f1f; padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
        a { color: #1f2430; }
    </style>
</head>
<body>
    <main>
        @if ($errors->any())
            <div class="errors">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
