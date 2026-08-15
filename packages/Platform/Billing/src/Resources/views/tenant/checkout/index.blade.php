<x-admin::layouts>
    <x-slot:title>
        Upgrade Plan
    </x-slot>

    <div class="flex items-center justify-between">
        <p class="text-xl font-bold text-gray-800 dark:text-white">
            Upgrade Plan
        </p>
    </div>

    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
        Select a plan and billing interval to proceed to checkout. You will be redirected to Stripe's secure payment page to complete your purchase.
    </p>

    @forelse ($plans as $plan)
        @php($planPrices = $prices->get($plan->id, collect()))

        @if ($planPrices->isNotEmpty())
            <x-admin::accordion class="mt-4">
                <x-slot:header>
                    <div class="flex items-center gap-2.5">
                        <p class="text-base font-semibold text-gray-800 dark:text-white">
                            {{ $plan->name }}
                        </p>
                    </div>
                </x-slot>

                <x-slot:content>
                    <x-admin::table>
                        <x-admin::table.thead>
                            <x-admin::table.thead.tr>
                                <x-admin::table.th>Interval</x-admin::table.th>
                                <x-admin::table.th>Amount</x-admin::table.th>
                                <x-admin::table.th></x-admin::table.th>
                            </x-admin::table.thead.tr>
                        </x-admin::table.thead>

                        <x-admin::table.tbody>
                            @foreach ($planPrices as $price)
                                <x-admin::table.tbody.tr>
                                    <x-admin::table.td>{{ ucfirst($price->billing_interval->value) }}</x-admin::table.td>
                                    <x-admin::table.td>{{ number_format($price->amount_minor / 100, 2) }} {{ $price->currency }}</x-admin::table.td>
                                    <x-admin::table.td>
                                        <form method="POST" action="{{ route('admin.saas.checkout.store') }}">
                                            @csrf
                                            <input type="hidden" name="plan_price_id" value="{{ $price->id }}">
                                            <button type="submit" class="primary-button">
                                                Select
                                            </button>
                                        </form>
                                    </x-admin::table.td>
                                </x-admin::table.tbody.tr>
                            @endforeach
                        </x-admin::table.tbody>
                    </x-admin::table>
                </x-slot>
            </x-admin::accordion>
        @endif
    @empty
        <div class="mt-4 rounded-lg border border-gray-200 bg-white p-4 text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            No plans are currently available for checkout.
        </div>
    @endforelse
</x-admin::layouts>
