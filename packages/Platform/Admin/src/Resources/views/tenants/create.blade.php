@extends('platform::layouts.app', ['title' => 'Create Merchant'])

@section('content')
    <p><a href="{{ route('platform.tenants.index') }}">&larr; Back to tenants</a></p>

    <h1>Create Merchant</h1>

    <div class="card" style="max-width: 480px;">
        {{--
            TASK-MVP-007. Managed onboarding - no password field. The
            merchant sets their own password via the real Admin
            password-reset flow, triggered right after provisioning
            succeeds (see Platform\Signup\Services\OwnerActivationMailer).
            Provisioning runs synchronously (~20-30s) - the submit button
            is disabled on submit to prevent an accidental double-submit
            from starting a second store creation for the same slug.
        --}}
        <form method="POST" action="{{ route('platform.tenants.store') }}" onsubmit="this.querySelector('button[type=submit]').disabled = true; this.querySelector('button[type=submit]').textContent = 'Creating store...';">
            @csrf

            <p>
                <label for="store_name">Store name</label><br>
                <input type="text" id="store_name" name="store_name" value="{{ old('store_name') }}">
            </p>

            <p>
                <label for="slug">Store address (slug)</label><br>
                <input type="text" id="slug" name="slug" value="{{ old('slug') }}" placeholder="e.g. acme">
                <br><small>Lowercase letters, numbers, hyphens only. Becomes {{ old('slug') ?: 'acme' }}.{{ config('platform.base_domain') }}.</small>
            </p>

            <p>
                <label for="owner_first_name">Owner first name</label><br>
                <input type="text" id="owner_first_name" name="owner_first_name" value="{{ old('owner_first_name') }}">
            </p>

            <p>
                <label for="owner_last_name">Owner last name</label><br>
                <input type="text" id="owner_last_name" name="owner_last_name" value="{{ old('owner_last_name') }}">
            </p>

            <p>
                <label for="owner_email">Owner email</label><br>
                <input type="email" id="owner_email" name="owner_email" value="{{ old('owner_email') }}">
                <br><small>The merchant will receive a password-reset email at this address to set their own password.</small>
            </p>

            <p>
                <label for="plan_id">Plan</label><br>
                <select id="plan_id" name="plan_id">
                    @foreach ($plans as $plan)
                        <option value="{{ $plan->id }}" {{ (string) old('plan_id') === (string) $plan->id ? 'selected' : '' }}>{{ $plan->name }}</option>
                    @endforeach
                </select>
            </p>

            <button type="submit">Create Merchant</button>
        </form>
    </div>
@endsection
