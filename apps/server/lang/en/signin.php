<?php

declare(strict_types=1);

/*
 * The sign-in surface.
 *
 * One file per surface, named after the surface, keys named after what the
 * string is rather than what it says -- `submit`, not `sign_in` -- so a change
 * of wording is not a change of key.
 */

return [
    'login' => [
        'title' => 'Agent Login',
        'lede' => 'Sign in to your Wayfindr support workspace.',
        'email' => 'Email',
        'password' => 'Password',
        'remember' => 'Remember this browser',
        'submit' => 'Sign in',
        'forgot' => 'Forgotten your password?',
    ],
    'forgot' => [
        'title' => 'Reset your password',
        'lede' => 'We will email you a link to set a new one.',
        'email' => 'Email',
        'submit' => 'Email me a reset link',
        'back' => 'Back to sign in',
    ],
    'reset' => [
        'title' => 'Choose a new password',
        'lede' => 'This also signs you out everywhere else.',
        'email' => 'Email',
        'password' => 'New password',
        'confirm' => 'Confirm new password',
        'submit' => 'Set new password',
    ],
];
