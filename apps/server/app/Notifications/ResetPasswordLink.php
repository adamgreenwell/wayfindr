<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The reset link, delivered off the request.
 *
 * Queued deliberately, and not only to match how every other notification in
 * the product is sent. Sending inline made the forgot-password form leak which
 * addresses are real: a known address paid for an SMTP round trip while an
 * unknown one returned immediately, and an SMTP failure turned a known address
 * into a 500 while an unknown one still got the generic answer.
 *
 * Handing both to the queue makes the two paths indistinguishable from outside.
 */
class ResetPasswordLink extends ResetPassword implements ShouldQueue
{
    use InteractsWithQueue;
}
