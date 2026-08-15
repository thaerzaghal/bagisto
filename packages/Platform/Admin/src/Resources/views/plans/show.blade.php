@extends('platform::layouts.app', ['title' => 'Plan: '.$plan->name])

@section('content')
    <p><a href="{{ route('platform.plans.index') }}">&larr; Back to plans</a></p>

    <h1>Plan: {{ $plan->name }} <span class="status status-{{ $plan->is_active ? 'ready' : 'suspended' }}">{{ $plan->is_active ? 'active' : 'inactive' }}</span></h1>

    <div class="card" style="margin-bottom: 1.5rem;">
        <h2 style="margin-top: 0;">Details</h2>

        <form method="POST" action="{{ route('platform.plans.update', $plan) }}">
            @csrf
            @method('PATCH')

            <p>
                <label>Code</label><br>
                <input type="text" value="{{ $plan->code }}" disabled>
                <br><small>Code is permanent and cannot be changed after creation - it is referenced by provisioning configuration (<code>PLATFORM_DEFAULT_PLAN_CODE</code>).</small>
            </p>

            <p>
                <label for="name">Name</label><br>
                <input type="text" id="name" name="name" value="{{ old('name', $plan->name) }}">
            </p>

            <p>
                <label for="description">Description</label><br>
                <textarea id="description" name="description">{{ old('description', $plan->description) }}</textarea>
            </p>

            <p>
                <label for="sort_order">Sort order</label><br>
                <input type="number" id="sort_order" name="sort_order" value="{{ old('sort_order', $plan->sort_order) }}" min="0">
            </p>

            <button type="submit">Save changes</button>
        </form>

        <p style="margin-top: 1rem;">
            @if ($plan->is_active)
                <form class="inline" method="POST" action="{{ route('platform.plans.deactivate', $plan) }}" onsubmit="return confirm('Deactivate this plan? It will no longer be available for new/manual assignment. Tenants already on it are unaffected.');">
                    @csrf
                    <button type="submit">Deactivate</button>
                </form>
            @else
                <form class="inline" method="POST" action="{{ route('platform.plans.activate', $plan) }}">
                    @csrf
                    <button type="submit">Activate</button>
                </form>
            @endif
        </p>
    </div>

    <div class="card" style="margin-bottom: 1.5rem;">
        <h2 style="margin-top: 0;">Prices</h2>

        <table>
            <thead>
                <tr>
                    <th>Interval</th>
                    <th>Interval count</th>
                    <th>Amount (minor units)</th>
                    <th>Currency</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($prices as $price)
                    <tr>
                        <td>
                            <form class="inline" method="POST" action="{{ route('platform.plans.prices.update', [$plan, $price]) }}">
                                @csrf
                                @method('PATCH')
                                <select name="billing_interval">
                                    @foreach ($billingIntervals as $interval)
                                        <option value="{{ $interval->value }}" {{ $price->billing_interval === $interval ? 'selected' : '' }}>{{ $interval->value }}</option>
                                    @endforeach
                                </select>
                        </td>
                        <td>
                                <input type="number" name="interval_count" min="1" value="{{ $price->interval_count }}" style="width: 5rem;">
                        </td>
                        <td>
                                <input type="number" name="amount_minor" min="0" value="{{ $price->amount_minor }}" style="width: 7rem;">
                        </td>
                        <td>
                                <input type="text" name="currency" maxlength="3" value="{{ $price->currency }}" style="width: 4rem;">
                        </td>
                        <td>{{ $price->is_active ? 'active' : 'inactive' }}</td>
                        <td>
                                <button type="submit">Save</button>
                            </form>
                            @if ($price->is_active)
                                <form class="inline" method="POST" action="{{ route('platform.plans.prices.deactivate', [$plan, $price]) }}">
                                    @csrf
                                    <button type="submit">Deactivate</button>
                                </form>
                            @else
                                <form class="inline" method="POST" action="{{ route('platform.plans.prices.activate', [$plan, $price]) }}">
                                    @csrf
                                    <button type="submit">Activate</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6">No prices configured for this plan.</td></tr>
                @endforelse
            </tbody>
        </table>

        <h3>Add price</h3>

        <form method="POST" action="{{ route('platform.plans.prices.store', $plan) }}">
            @csrf

            <select name="billing_interval">
                @foreach ($billingIntervals as $interval)
                    <option value="{{ $interval->value }}">{{ $interval->value }}</option>
                @endforeach
            </select>

            <input type="number" name="interval_count" min="1" value="1" placeholder="interval count" style="width: 5rem;">

            <input type="number" name="amount_minor" min="0" placeholder="amount (minor units)" style="width: 7rem;">

            <input type="text" name="currency" maxlength="3" placeholder="USD" style="width: 4rem;">

            <button type="submit">Add price</button>
        </form>

        <p><small>Amounts are integer minor units (e.g. 1000 = 10.00 for a 2-decimal currency) - never a decimal/float.</small></p>
    </div>

    <div class="card">
        <h2 style="margin-top: 0;">Features</h2>

        <table>
            <thead>
                <tr>
                    <th>Feature code</th>
                    <th>Type</th>
                    <th>Value</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($features as $feature)
                    <tr>
                        <td>{{ $feature->feature_code }}</td>
                        <td>
                            <form class="inline" method="POST" action="{{ route('platform.plans.features.update', [$plan, $feature]) }}">
                                @csrf
                                @method('PATCH')
                                <select name="type" onchange="this.closest('tr').querySelector('.value-input').disabled = (this.value === 'unlimited')">
                                    @foreach ($featureTypes as $type)
                                        <option value="{{ $type->value }}" {{ $feature->type === $type ? 'selected' : '' }}>{{ $type->value }}</option>
                                    @endforeach
                                </select>
                        </td>
                        <td>
                                <input class="value-input" type="number" name="value" min="0" value="{{ $feature->value }}" {{ $feature->type === \Platform\Plans\Enums\FeatureType::Unlimited ? 'disabled' : '' }}>
                        </td>
                        <td>
                                <button type="submit">Save</button>
                            </form>
                            <form class="inline" method="POST" action="{{ route('platform.plans.features.destroy', [$plan, $feature]) }}" onsubmit="return confirm('Remove this feature from the plan?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit">Remove</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4">No features configured for this plan.</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($availableFeatureCodes->isNotEmpty())
            <h3>Add feature</h3>

            <form method="POST" action="{{ route('platform.plans.features.store', $plan) }}">
                @csrf

                <select name="feature_code">
                    @foreach ($availableFeatureCodes as $code)
                        <option value="{{ $code->value }}">{{ $code->value }}</option>
                    @endforeach
                </select>

                <select name="type" onchange="this.closest('form').querySelector('.value-input').disabled = (this.value === 'unlimited')">
                    @foreach ($featureTypes as $type)
                        <option value="{{ $type->value }}">{{ $type->value }}</option>
                    @endforeach
                </select>

                <input class="value-input" type="number" name="value" min="0" placeholder="value (ignored if unlimited)">

                <button type="submit">Add feature</button>
            </form>
        @else
            <p><small>Every known feature code is already configured on this plan.</small></p>
        @endif
    </div>
@endsection
