<?php

namespace App\Listeners\Billing;

use Vendor\LaravelBilling\Events\PaymentFailed;

class NotifySlackOnPaymentFailure
{
    public function handle(PaymentFailed $event): void
    {
        // Send notification to Slack channel

        // $user = $event->user;
    }
}