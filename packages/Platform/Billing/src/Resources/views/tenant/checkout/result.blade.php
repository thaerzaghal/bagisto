<x-admin::layouts>
    <x-slot:title>
        Checkout {{ $outcome === 'success' ? 'Submitted' : 'Canceled' }}
    </x-slot>

    <div class="flex items-center justify-between">
        <p class="text-xl font-bold text-gray-800 dark:text-white">
            @if ($outcome === 'cancel')
                Checkout Canceled
            @else
                Checkout Submitted
            @endif
        </p>
    </div>

    {{--
        TASK-ARCH-019 (task section 8, strict): this page NEVER claims
        "Payment succeeded" on its own authority - it only ever displays
        whatever $payment->status already says, read fresh from the
        database. Only a verified Stripe webhook event
        (Platform\Billing\Services\WebhookEventProcessor) can ever set
        that status to Succeeded.
    --}}
    @if (! $payment)
        <div class="mt-4 rounded-lg border border-gray-200 bg-white p-4 text-gray-600 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-300">
            No payment record was found.
        </div>
    @else
        <div class="mt-4 rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Status</p>
                    <p class="text-base font-medium text-gray-800 dark:text-white">
                        {{ ucfirst(str_replace('_', ' ', $payment->status->value)) }}
                    </p>
                </div>

                <div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Amount</p>
                    <p class="text-base font-medium text-gray-800 dark:text-white">
                        {{ number_format($payment->amount_minor / 100, 2) }} {{ $payment->currency }}
                    </p>
                </div>
            </div>

            @if ($payment->status === \Platform\Billing\Enums\PaymentStatus::Pending || $payment->status === \Platform\Billing\Enums\PaymentStatus::RequiresAction)
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                    Your payment is still being confirmed. This page does not update automatically - refresh to check again.
                </p>
            @elseif ($payment->status === \Platform\Billing\Enums\PaymentStatus::Succeeded)
                <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                    Payment confirmed. Your plan has been updated - see <a href="{{ route('admin.saas.plan.index') }}" class="text-blue-600 hover:underline">My Plan</a>.
                </p>
            @endif
        </div>
    @endif

    <p class="mt-4">
        <a href="{{ route('admin.saas.plan.index') }}" class="text-blue-600 hover:underline">&larr; Back to My Plan</a>
    </p>
</x-admin::layouts>
