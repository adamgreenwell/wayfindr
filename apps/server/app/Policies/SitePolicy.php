<?php

namespace App\Policies;

use App\Models\Site;
use App\Models\User;

class SitePolicy
{
    public function view(User $user, Site $site): bool
    {
        return ! $user->isDeactivated()
            && $site->supportsAgent($user);
    }

    public function updatePrivacy(User $user, Site $site): bool
    {
        return $user->isAdmin() && $this->view($user, $site);
    }

    public function manageAccess(User $user, Site $site): bool
    {
        return $user->isAdmin() && $this->view($user, $site);
    }

    public function manageIntegrations(User $user, Site $site): bool
    {
        return $user->isAdmin() && $this->view($user, $site);
    }

    /**
     * Edit a site's name and domain.
     *
     * Neither field authenticates anything - the widget resolves a site by its
     * public key alone, and the domain is used for display and install
     * diagnostics - so this is the same bar as the other site settings.
     */
    public function update(User $user, Site $site): bool
    {
        return $user->isAdmin() && $this->view($user, $site);
    }

    /**
     * Take a site out of service, or return it.
     *
     * Reversible and lossless, so it sits at the ordinary admin bar.
     */
    public function archive(User $user, Site $site): bool
    {
        return $user->isAdmin() && $this->view($user, $site);
    }

    /**
     * Destroy a site and everything beneath it.
     *
     * Deliberately stricter than every other site ability: the cascade takes
     * the conversations, tickets, visitors, cobrowse sessions and the site's
     * own audit events with it, and no amount of care makes that reversible
     * outside a restore. Owners only.
     */
    public function purge(User $user, Site $site): bool
    {
        return $user->isOwner() && $this->view($user, $site);
    }
}
