<?php

use App\Support\Settings\OperatorSettings;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->in('Feature');

/**
 * Confirm the install's language and clock.
 *
 * Language and region is an essential now, and it appears on BOTH readiness
 * surfaces -- so a fixture that means "nothing needs attention" has to say so
 * about this too, exactly as it already does about mail and backups.
 *
 * Declared here rather than in a test file because Pest helpers are global:
 * two files defining the same name is a fatal that takes down the whole suite
 * before a single test runs.
 */
function readinessLanguageAndRegionConfirmed(): void
{
    $settings = app(OperatorSettings::class);
    $settings->set('localization.language', 'en');
    $settings->set('localization.timezone', 'UTC');
}
