@extends('platform::layouts.app', ['title' => 'Tenant: '.$tenant->getTenantKey()])

@section('content')
    <p><a href="{{ route('platform.tenants.index') }}">&larr; Back to tenants</a></p>

    <h1>Tenant: {{ $tenant->getTenantKey() }}</h1>

    <div class="card">
        <table>
            <tr><th>Tenant ID</th><td>{{ $tenant->getTenantKey() }}</td></tr>
            <tr><th>Status</th><td><span class="status status-{{ $tenant->status->value }}">{{ $tenant->status->value }}</span></td></tr>
            <tr><th>Owner</th><td>{{ $tenant->owner_name ?: '—' }}</td></tr>
            <tr><th>Owner email</th><td>{{ $tenant->owner_email ?: '—' }}</td></tr>
            <tr>
                <th>Domains</th>
                <td>
                    @forelse ($tenant->domains as $domain)
                        <div>
                            {{ $domain->domain }}
                            &mdash;
                            <a href="{{ request()->isSecure() ? 'https' : 'http' }}://{{ $domain->domain }}" target="_blank" rel="noopener">Storefront</a>
                            &middot;
                            <a href="{{ request()->isSecure() ? 'https' : 'http' }}://{{ $domain->domain }}/{{ config('app.admin_url') }}/login" target="_blank" rel="noopener">Merchant Admin</a>
                        </div>
                    @empty
                        &mdash;
                    @endforelse
                </td>
            </tr>
            <tr><th>Plan</th><td>{{ $plan?->name ?? '—' }}{{ $plan && ! $plan->is_active ? ' (inactive)' : '' }}</td></tr>
            <tr><th>Last error</th><td>{{ $tenant->last_error ?: '—' }}</td></tr>
            <tr><th>Created at</th><td>{{ $tenant->created_at }}</td></tr>
            <tr><th>Updated at</th><td>{{ $tenant->updated_at }}</td></tr>
        </table>
    </div>

    {{--
        TASK-MVP-015. Derived-only onboarding readiness summary - see
        Platform\Admin\Http\Controllers\TenantController::
        onboardingReadiness()'s own docblock. Deliberately narrow: a null
        status means "not applicable / not yet knowable", never rendered
        as either pass or fail.
    --}}
    <div class="card" style="margin-top: 1.5rem; max-width: 560px;">
        <h2 style="margin-top: 0;">Onboarding Status</h2>
        <table>
            @foreach ($readiness as $check)
                <tr>
                    <th>{{ $check['label'] }}</th>
                    <td>
                        @if ($check['status'] === true)
                            <span class="status status-ready">Yes</span>
                        @elseif ($check['status'] === false)
                            <span class="status status-suspended">No</span>
                        @else
                            <span class="status status-pending">N/A</span>
                        @endif
                        <br><small>{{ $check['detail'] }}</small>
                    </td>
                </tr>
            @endforeach
        </table>
        <p><small>This summary is derived live from existing tenant/plan/subscription/locale state - it is never stored, and it deliberately does not cover payment, shipping, tax, catalog, checkout, or merchant-activation readiness. See <code>docs/operations/merchant-onboarding-checklist.md</code> for the full manual checklist.</small></p>
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

        {{-- TASK-MVP-007. Does not re-run provisioning - see OwnerActivationMailer. --}}
        @if ($tenant->status === \Platform\Tenancy\Enums\TenantStatus::Ready)
            <form class="inline" method="POST" action="{{ route('platform.tenants.resend-activation', $tenant->getTenantKey()) }}">
                @csrf
                <button type="submit">Resend activation email</button>
            </form>
        @endif
    </p>

    <div class="card" style="margin-top: 1.5rem; max-width: 480px;">
        <h2 style="margin-top: 0;">Subscription</h2>

        @if ($subscription)
            <table>
                <tr><th>Status</th><td><span class="status status-{{ $subscription->status->value === 'active' ? 'ready' : ($subscription->status->value === 'canceled' || $subscription->status->value === 'expired' ? 'suspended' : 'pending') }}">{{ $subscription->status->value }}</span></td></tr>
                <tr><th>Plan</th><td>{{ $subscription->plan->name }}</td></tr>
                <tr><th>Starts at</th><td>{{ $subscription->starts_at }}</td></tr>
                @if ($subscription->trial_ends_at)
                    <tr><th>Trial ends at</th><td>{{ $subscription->trial_ends_at }}</td></tr>
                @endif
                @if ($subscription->current_period_start || $subscription->current_period_end)
                    <tr><th>Current period</th><td>{{ $subscription->current_period_start }} &rarr; {{ $subscription->current_period_end }}</td></tr>
                @endif
                <tr><th>Cancel at period end</th><td>{{ $subscription->cancel_at_period_end ? 'Yes' : 'No' }}</td></tr>
                @if ($subscription->cancelled_at)
                    <tr><th>Cancelled at</th><td>{{ $subscription->cancelled_at }}</td></tr>
                @endif
                @if ($subscription->ended_at)
                    <tr><th>Ended at</th><td>{{ $subscription->ended_at }}</td></tr>
                @endif
            </table>

            <p style="margin-top: 1rem;">
                @if ($subscription->status === \Platform\Subscriptions\Enums\SubscriptionStatus::Trialing)
                    <form class="inline" method="POST" action="{{ route('platform.tenants.subscription.activate', $tenant->getTenantKey()) }}">
                        @csrf
                        <button type="submit">Activate</button>
                    </form>
                @endif

                @if ($subscription->status === \Platform\Subscriptions\Enums\SubscriptionStatus::Active)
                    @unless ($subscription->cancel_at_period_end)
                        <form class="inline" method="POST" action="{{ route('platform.tenants.subscription.cancel-at-period-end', $tenant->getTenantKey()) }}" onsubmit="return confirm('Schedule this subscription to cancel at period end? The tenant keeps access until then - this only sets intent.');">
                            @csrf
                            <button type="submit">Cancel at period end</button>
                        </form>
                    @endunless
                @endif

                @if (in_array($subscription->status, [\Platform\Subscriptions\Enums\SubscriptionStatus::Trialing, \Platform\Subscriptions\Enums\SubscriptionStatus::Active], true))
                    <form class="inline" method="POST" action="{{ route('platform.tenants.subscription.cancel-immediately', $tenant->getTenantKey()) }}" onsubmit="return confirm('Cancel this subscription immediately? This does not change the tenant\'s access/plan/status on its own - those are separate, independent decisions.');">
                        @csrf
                        <button type="submit">Cancel immediately</button>
                    </form>
                @endif
            </p>
        @else
            <p>No subscription yet.</p>
        @endif
    </div>

    <div class="card" style="margin-top: 1.5rem;">
        <h2 style="margin-top: 0;">Recent Payments</h2>

        {{--
            TASK-ARCH-019 (task section 24): minimal operational visibility
            only - date/provider/amount/currency/status/provider reference.
            Deliberately NOT an accounting dashboard, and no raw provider
            payload (provider_metadata) is ever rendered here.
        --}}
        @if ($recentPayments->isEmpty())
            <p>No payments recorded for this tenant.</p>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Provider</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Provider reference</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($recentPayments as $payment)
                        <tr>
                            <td>{{ $payment->created_at->toDateTimeString() }}</td>
                            <td>{{ $payment->provider }}</td>
                            <td>{{ number_format($payment->amount_minor / 100, 2) }} {{ $payment->currency }}</td>
                            <td>{{ ucfirst(str_replace('_', ' ', $payment->status->value)) }}</td>
                            <td>{{ $payment->provider_reference ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card" style="margin-top: 1.5rem; max-width: 420px;">
        <h2 style="margin-top: 0;">Change Plan</h2>

        <form method="POST" action="{{ route('platform.tenants.change-plan', $tenant->getTenantKey()) }}">
            @csrf

            <select name="plan_id">
                @foreach ($assignablePlans as $assignable)
                    <option value="{{ $assignable->id }}" {{ $plan?->id === $assignable->id ? 'selected' : '' }}>
                        {{ $assignable->name }}{{ ! $assignable->is_active ? ' (inactive - current plan)' : '' }}
                    </option>
                @endforeach
            </select>

            <button type="submit">Change Plan</button>
        </form>
        <p><small>{{ $subscription ? 'Changes the tenant\'s subscription plan.' : 'No subscription exists yet - this will start one.' }}</small></p>
    </div>
@endsection
