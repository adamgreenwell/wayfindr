<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Written only through the account-locked policy service. No visitor content. */
final class AccountAiAssistPolicy extends Model
{
    protected $primaryKey = 'account_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'summary' => 'boolean', 'reply_draft' => 'boolean',
            'relevant_articles' => 'boolean', 'ticket_details' => 'boolean',
            'article_context_consent' => 'boolean', 'max_context_characters' => 'integer',
        ];
    }
}
