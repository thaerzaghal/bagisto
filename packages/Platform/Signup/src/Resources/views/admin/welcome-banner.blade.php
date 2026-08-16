<div id="platform-welcome-banner" style="background:#eaf6ec;border:1px solid #b7e0c0;border-radius:6px;padding:0.9rem 1.1rem;margin-bottom:1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;">
    <span style="color:#12603a;">
        Welcome! Your store is ready.
        <a href="{{ route('admin.catalog.products.index') }}" style="color:#12603a;font-weight:600;text-decoration:underline;">Add your first product</a>
        to start selling.
    </span>
    <button
        type="button"
        onclick="document.getElementById('platform-welcome-banner').remove()"
        style="background:none;border:none;color:#12603a;cursor:pointer;font-size:1.1rem;line-height:1;padding:0 0.25rem;"
        aria-label="Dismiss"
    >&times;</button>
</div>
