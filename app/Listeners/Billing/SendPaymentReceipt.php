<?php

namespace App\Listeners\Billing;

use Vendor\LaravelBilling\Events\PaymentSucceeded;

class SendPaymentReceipt
{
    public function handle(PaymentSucceeded $event): void
    {
        // Send payment receipt email to the user

        // $user = $event->user;
    }
}