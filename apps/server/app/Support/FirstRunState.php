<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Site;
use App\Models\User;

class FirstRunState
{
    public function needsSetup(): bool
    {
        return ! $this->hasAccountScopedUser();
    }

    public function hasIncompleteBootstrapRecords(): bool
    {
        return $this->needsSetup()
            && (Account::query()->exists() || Site::query()->exists());
    }

    private function hasAccountScopedUser(): bool
    {
        return User::query()->whereNotNull('account_id')->exists();
    }
}
