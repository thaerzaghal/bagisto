@extends('platform::layouts.app', ['title' => 'Tenants'])

@section('content')
    <h1>Tenants</h1>

    <table>
        <thead>
            <tr>
                <th>Tenant ID</th>
                <th>Status</th>
                <th>Domain</th>
                <th>Plan</th>
                <th>Error</th>
                <th>Created</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($tenants as $tenant)
                <tr>
                    <td><a href="{{ route('platform.tenants.show', $tenant->getTenantKey()) }}">{{ $tenant->getTenantKey() }}</a></td>
                    <td><span class="status status-{{ $tenant->status->value }}">{{ $tenant->status->value }}</span></td>
                    <td>{{ $tenant->domains->pluck('domain')->join(', ') ?: '—' }}</td>
                    <td>{{ $tenant->plan_id && $plans->has($tenant->plan_id) ? $plans[$tenant->plan_id]->name : '—' }}</td>
                    <td>{{ $tenant->last_error ?: '—' }}</td>
                    <td>{{ $tenant->created_at?->toDateString() }}</td>
                    <td><a href="{{ route('platform.tenants.show', $tenant->getTenantKey()) }}">View</a></td>
                </tr>
            @empty
                <tr><td colspan="7">No tenants yet.</td></tr>
            @endforelse
        </tbody>
    </table>
@endsection
