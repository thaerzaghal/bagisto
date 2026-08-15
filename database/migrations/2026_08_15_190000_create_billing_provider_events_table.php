<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ARCH-019 (task section 9). Central-only, matching every other
 * Platform billing/subscription table. The idempotency ledger for inbound
 * provider events - `Platform\Billing\Services\WebhookEventProcessor`
 * reads/writes this table inside the SAME database transaction as any
 * Payment/Subscription mutation the event triggers (see that class's own
 * docblock for why that atomicity is what actually makes duplicate/
 * replayed/crashed-mid-processing delivery safe, not this table alone).
 *
 * `unique(['provider', 'provider_event_id'])` is the load-bearing
 * invariant (task section 9, explicit) - a second row for the same
 * provider's same event id can never exist, regardless of application
 * code, mirroring `subscriptions.unique('tenant_id')`'s own precedent for
 * a database-level structural guarantee over an application-level one.
 *
 * `payment_id` is nullable: an event that never correlates to a known
 * Payment (task section 17 - unknown/spoofed provider reference) is still
 * recorded (status `Rejected`) for audit, without a Payment to attach to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('provider_event_id');
            $table->string('event_type');
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('processing_status');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);
            $table->index('processing_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_provider_events');
    }
};
