@extends('platform::layouts.app', ['title' => 'Tenant: '.$tenant->getTenantKey()])

@section('content')
    <p><a href="{{ route('platform.tenants.index') }}">&larr; Back to tenants</a></p>

    <h1>Tenant: {{ $tenant->getTenantKey() }}</h1>

    <div class="card">
        <table>
            <tr><th>Tenant ID</th><td>{{ $tenant->getTenantKey() }}</td></tr>
            <tr><th>Status</th><td><span class="status status-{{ $tenant->status->value }}">{{ $tenant->status->value }}</span></td></tr>
            <tr><th>Domains</th><td>{{ $tenant->domains->pluck('domain')->join(', ') ?: '—' }}</td></tr>
            <tr><th>Plan</th><td>{{ $plan?->name ?? '—' }}</td></tr>
            <tr><th>Last error</th><td>{{ $tenant->last_error ?: '—' }}</td></tr>
            <tr><th>Created at</th><td>{{ $tenant->created_at }}</td></tr>
            <tr><th>Updated at</th><td>{{ $tenant->updated_at }}</td></tr>
        </table>
    </div>

    <p style="margin-top: 1.5rem;">
        @if ($tenant->status->isProvisionable())
            <form class="inline" method="POST" action="{{ route('platform.tenants.provision', $tenant->getTenantKey()) }}">
                @csrf
                <button type="submit">Provision / Retry</button>
            </form>
        @endif

        <form class="inline" method="POST" action="{{ route('platform.tenants.migrate-pending', $tenant->getTenantKey()) }}">
            @csrf
            <button type="submit">Run pending migrations</button>
        </form>

        @if ($tenant->status === \Platform\Tenancy\Enums\TenantStatus::Ready)
            <form class="inline" method="POST" action="{{ route('platform.tenants.suspend', $tenant->getTenantKey()) }}" onsubmit="return confirm('Suspend this tenant? Their storefront, admin, and API will become inaccessible until reactivated.');">
                @csrf
                <button type="submit">Suspend</button>
            </form>
        @elseif ($tenant->status === \Platform\Tenancy\Enums\TenantStatus::Suspended)
            <form class="inline" method="POST" action="{{ route('platform.tenants.reactivate', $tenant->getTenantKey()) }}" onsubmit="return confirm('Reactivate this tenant? Their storefront, admin, and API will become accessible again immediately.');">
                @csrf
                <button type="submit">Reactivate</button>
            </form>
        @endif
    </p>
@endsection
