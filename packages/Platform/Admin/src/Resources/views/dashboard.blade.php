@extends('platform::layouts.app', ['title' => 'Platform Dashboard'])

@section('content')
    <h1>Platform Dashboard</h1>

    <div class="metrics">
        <div class="card">
            <strong>{{ $tenantCount }}</strong>
            Tenants (total)
        </div>
        <div class="card">
            <strong>{{ $readyTenantCount }}</strong>
            Ready tenants
        </div>
        <div class="card">
            <strong>{{ $failedTenantCount }}</strong>
            Failed tenants
        </div>
        <div class="card">
            <strong>{{ $activePlanCount }}</strong>
            Active plans
        </div>
    </div>
@endsection
