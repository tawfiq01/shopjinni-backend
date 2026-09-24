<?php

namespace App\Domain\Subscriptions\Console\Commands;

use App\Domain\Subscriptions\Models\Subscription;
use App\Domain\Subscriptions\Services\SubscriptionLifecycleService;
use Illuminate\Console\Command;

/**
 * Runs daily (see routes/console.php) and advances every non-lifetime
 * subscription one lifecycle step if its clock has run out. All the
 * actual transition rules live in SubscriptionLifecycleService::tick()
 * so a Super Admin action (recordPayment, suspend) can't drift from what
 * this command does.
 */
class CheckSubscriptionStatuses extends Command
{
    protected $signature = 'subscriptions:check-status';

    protected $description = 'Advance every company\'s subscription lifecycle status (trial/payment_due/grace/expired)';

    public function handle(SubscriptionLifecycleService $lifecycle): int
    {
        $now = now()->toImmutable();

        $count = 0;
        Subscription::where('is_lifetime', false)
            ->whereNotIn('status', [Subscription::STATUS_SUSPENDED])
            ->chunkById(100, function ($subscriptions) use ($lifecycle, $now, &$count) {
                foreach ($subscriptions as $subscription) {
                    $lifecycle->tick($subscription, $now);
                    $count++;
                }
            });

        $this->info("Checked {$count} subscriptions.");

        return self::SUCCESS;
    }
}
