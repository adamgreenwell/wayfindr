<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\Visitors\AlertMailErasureGuard;
use Illuminate\Mail\Events\MessageSent;

/** An alert mail has crossed to the mail server: it no longer holds erasure. */
final class FinishAlertMailSend
{
    public function handle(MessageSent $event): void
    {
        AlertMailErasureGuard::finish($event->message);
    }
}
