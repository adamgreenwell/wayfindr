<?php

namespace App\Models;

use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    public function agents(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function ticketLabels(): HasMany
    {
        return $this->hasMany(TicketLabel::class);
    }

    public function ticketExternalLinks(): HasMany
    {
        return $this->hasMany(TicketExternalLink::class);
    }

    public function externalIssueProviderConnections(): HasMany
    {
        return $this->hasMany(ExternalIssueProviderConnection::class);
    }

    public function siteExternalIssueProjects(): HasMany
    {
        return $this->hasMany(SiteExternalIssueProject::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }
}
