<?php

namespace App\Rules;

use App\Support\Webhooks\OutboundWebhookDestination;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PublicWebhookUrl implements ValidationRule
{
    public function __construct(private readonly OutboundWebhookDestination $destination) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! $this->destination->isAllowed($value)) {
            $fail(__('outbound_webhooks.validation.url'));
        }
    }
}
