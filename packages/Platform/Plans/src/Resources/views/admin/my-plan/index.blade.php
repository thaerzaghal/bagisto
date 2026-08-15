<x-admin::layouts>
    <x-slot:title>
        My Plan
    </x-slot>

    <div class="flex items-center justify-between">
        <p class="text-xl font-bold text-gray-800 dark:text-white">
            My Plan
        </p>

        {{--
            TASK-ARCH-019. A plain route() link to Platform\Billing's own
            checkout page - deliberately NOT a PHP/controller-level
            dependency on Platform\Billing from this package (Platform\Plans
            must never depend on Platform\Billing - see
            Platform\Billing\Providers\BillingServiceProvider's own
            dependency-direction docblock). A Blade route() call resolves
            at runtime against whatever is registered, with zero compile-
            time import - this is the only connection between the two
            packages' UI.
        --}}
        @if (\Illuminate\Support\Facades\Route::has('admin.saas.checkout.index'))
            <a href="{{ route('admin.saas.checkout.index') }}" class="primary-button">
                Upgrade Plan
            </a>
        @endif
    </div>

    @if (! $plan)
        <div class="mt-4 rounded-lg border border-gray-200 bg-white p-4 text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            No plan is currently assigned to this account.
        </div>
    @else
        <x-admin::accordion class="mt-4">
            <x-slot:header>
                <div class="flex items-center gap-2.5">
                    <p class="text-base font-semibold text-gray-800 dark:text-white">
                        Plan
                    </p>
                </div>
            </x-slot>

            <x-slot:content>
                <div class="grid grid-cols-2 gap-4 p-2 sm:grid-cols-3">
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Name</p>
                        <p class="text-base font-medium text-gray-800 dark:text-white">{{ $plan->name }}</p>
                    </div>

                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Code</p>
                        <p class="text-base font-medium text-gray-800 dark:text-white">{{ $plan->code }}</p>
                    </div>

                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Status</p>
                        <p class="text-base font-medium text-gray-800 dark:text-white">
                            {{ $plan->is_active ? 'Active' : 'Inactive' }}
                        </p>
                    </div>
                </div>
            </x-slot>
        </x-admin::accordion>

        <x-admin::accordion class="mt-4">
            <x-slot:header>
                <div class="flex items-center gap-2.5">
                    <p class="text-base font-semibold text-gray-800 dark:text-white">
                        Features
                    </p>
                </div>
            </x-slot>

            <x-slot:content>
                @if ($features->isEmpty())
                    <p class="p-2 text-sm text-gray-500 dark:text-gray-400">
                        No features are configured for this plan.
                    </p>
                @else
                    <x-admin::table>
                        <x-admin::table.thead>
                            <x-admin::table.thead.tr>
                                <x-admin::table.th>
                                    Feature
                                </x-admin::table.th>

                                <x-admin::table.th>
                                    Value
                                </x-admin::table.th>
                            </x-admin::table.thead.tr>
                        </x-admin::table.thead>

                        <x-admin::table.tbody>
                            @foreach ($features as $feature)
                                <x-admin::table.tbody.tr>
                                    <x-admin::table.td>
                                        {{ \Platform\Plans\Presentation\FeaturePresenter::label($feature) }}
                                    </x-admin::table.td>

                                    <x-admin::table.td>
                                        {{ \Platform\Plans\Presentation\FeaturePresenter::value($feature) }}
                                    </x-admin::table.td>
                                </x-admin::table.tbody.tr>
                            @endforeach
                        </x-admin::table.tbody>
                    </x-admin::table>
                @endif
            </x-slot>
        </x-admin::accordion>

        @if ($subscription)
            <x-admin::accordion class="mt-4">
                <x-slot:header>
                    <div class="flex items-center gap-2.5">
                        <p class="text-base font-semibold text-gray-800 dark:text-white">
                            Subscription
                        </p>
                    </div>
                </x-slot>

                <x-slot:content>
                    <div class="grid grid-cols-2 gap-4 p-2 sm:grid-cols-3">
                        <div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Status</p>
                            <p class="text-base font-medium text-gray-800 dark:text-white">
                                {{ ucfirst($subscription->status->value) }}
                            </p>
                        </div>

                        @if ($subscription->status === \Platform\Subscriptions\Enums\SubscriptionStatus::Trialing && $subscription->trial_ends_at)
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Trial ends</p>
                                <p class="text-base font-medium text-gray-800 dark:text-white">
                                    {{ $subscription->trial_ends_at->toFormattedDateString() }}
                                </p>
                            </div>
                        @endif

                        @if ($subscription->current_period_start && $subscription->current_period_end)
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Current period</p>
                                <p class="text-base font-medium text-gray-800 dark:text-white">
                                    {{ $subscription->current_period_start->toFormattedDateString() }} &rarr; {{ $subscription->current_period_end->toFormattedDateString() }}
                                </p>
                            </div>
                        @endif

                        @if ($subscription->cancel_at_period_end)
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Cancellation</p>
                                <p class="text-base font-medium text-gray-800 dark:text-white">
                                    Scheduled to cancel at period end
                                </p>
                            </div>
                        @endif
                    </div>
                </x-slot>
            </x-admin::accordion>
        @endif
    @endif
</x-admin::layouts>
