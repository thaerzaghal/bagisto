<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title ?? 'Platform Admin' }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; background: #f4f5f7; color: #1f2430; }
        header { background: #1f2430; color: #fff; padding: 1rem 1.5rem; display: flex; justify-content: space-between; align-items: center; }
        header a { color: #fff; text-decoration: none; margin-right: 1rem; }
        header nav a { color: #cfd4e0; }
        main { padding: 1.5rem; max-width: 1100px; margin: 0 auto; }
        table { width: 100%; border-collapse: collapse; background: #fff; }
        th, td { text-align: left; padding: 0.6rem 0.8rem; border-bottom: 1px solid #e3e5ea; }
        th { background: #eceff3; }
        .card { background: #fff; border-radius: 6px; padding: 1rem 1.25rem; box-shadow: 0 1px 2px rgba(0,0,0,0.06); }
        .metrics { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .metrics .card { flex: 1; min-width: 160px; }
        .metrics .card strong { display: block; font-size: 1.8rem; }
        .status { padding: 2px 8px; border-radius: 4px; font-size: 0.8rem; }
        .status-ready { background: #d7f5df; color: #12603a; }
        .status-failed { background: #fbdada; color: #8f1f1f; }
        .status-pending, .status-provisioning { background: #fdf1cf; color: #8a5a00; }
        .status-suspended, .status-deleting, .status-deleted { background: #e2e4ea; color: #43485a; }
        form.inline { display: inline; }
        button { cursor: pointer; }
        .errors { background: #fbdada; color: #8f1f1f; padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
        .flash { background: #d7f5df; color: #12603a; padding: 0.75rem 1rem; border-radius: 4px; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <header>
        <div><strong>Platform Admin</strong></div>
        @auth('platform')
            <nav>
                <a href="{{ route('platform.dashboard') }}">Dashboard</a>
                <a href="{{ route('platform.tenants.index') }}">Tenants</a>
                <a href="{{ route('platform.plans.index') }}">Plans</a>
                <form class="inline" method="POST" action="{{ route('platform.logout') }}">
                    @csrf
                    <button type="submit">Log out</button>
                </form>
            </nav>
        @endauth
    </header>
    <main>
        @if (session('status'))
            <div class="flash">{{ session('status') }}</div>
        @endif

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
