<?php

return [
    // How many days a subscription stays in "payment_due" (still fully
    // usable, just warned) before falling into "grace".
    'payment_due_days' => env('SUBSCRIPTION_PAYMENT_DUE_DAYS', 3),

    // How many further days in "grace" (blocked, but recoverable) before
    // the subscription is marked "expired".
    'grace_period_days' => env('SUBSCRIPTION_GRACE_PERIOD_DAYS', 4),
];
