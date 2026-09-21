<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Site;
use App\Support\Visitors\VisitorIdentityVerification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'name' => fake()->company().' Support Site',
            'domain' => fake()->unique()->domainName(),
            'public_key' => 'site_'.Str::lower(Str::random(32)),
            'settings' => [
                'mask_selectors' => ['input[type="password"]', '[data-wayfindr-mask]'],
            ],
            // An EXISTING site, deliberately, which is not what the product
            // creates today: a site made through the dashboard or first-run
            // setup starts with verification REQUIRED
            // (VisitorIdentityVerification::newSiteDefaults()).
            //
            // The divergence is on purpose and worth stating, because a factory
            // that quietly disagrees with production is how a suite ends up
            // green about a configuration nobody runs. Most tests here predate
            // verification and are about something else; making them all sign
            // their identifiers would bury what they are actually asserting.
            // The product's own default is asserted directly instead, in
            // SiteIdentityVerificationSettingsTest, and the REQUIRED path has
            // its own coverage in VisitorIdentityVerificationTest.
            //
            // Reach for `verifyingIdentity()` when a test is about that path.
            'identity_verification' => VisitorIdentityVerification::OFF,
        ];
    }

    /** A site that requires a signed identifier, with a secret to sign with. */
    public function verifyingIdentity(): static
    {
        $generated = VisitorIdentityVerification::generateSecret();

        return $this->state(fn (): array => [
            'identity_verification' => VisitorIdentityVerification::REQUIRED,
            'identity_secret' => $generated['plain'],
            'identity_secret_last_four' => $generated['last_four'],
        ]);
    }
}
