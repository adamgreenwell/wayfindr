<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Auth\TwoFactorAuthentication;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

final class AgentProfileTwoFactorController extends Controller
{
    public const ENROLMENT_SESSION_KEY = 'two_factor.enrolment_secret';

    public const RECOVERY_CODES_SESSION_KEY = 'two_factor.recovery_codes';

    public function start(Request $request, TwoFactorAuthentication $twoFactor): RedirectResponse
    {
        $request->validateWithBag('twoFactorStart', [
            'current_password' => ['required', 'current_password'],
        ]);

        if ($request->user()->hasTwoFactorAuthentication()) {
            throw ValidationException::withMessages([
                'current_password' => __('two_factor.profile.already_enabled'),
            ])->errorBag('twoFactorStart');
        }

        $request->session()->put(
            self::ENROLMENT_SESSION_KEY,
            Crypt::encryptString($twoFactor->generateSecret()),
        );

        return redirect()
            ->route('dashboard.profile.show')
            ->with('status', 'two_factor.flash.enrolment_started');
    }

    public function confirm(Request $request, TwoFactorAuthentication $twoFactor): RedirectResponse
    {
        $validated = $request->validateWithBag('twoFactorConfirm', [
            'one_time_code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);
        $secret = $this->pendingSecret($request);

        if (! $secret) {
            throw ValidationException::withMessages([
                'one_time_code' => __('two_factor.profile.enrolment_expired'),
            ])->errorBag('twoFactorConfirm');
        }

        $recoveryCodes = $twoFactor->confirm($request->user(), $secret, $validated['one_time_code']);

        if (! $recoveryCodes) {
            throw ValidationException::withMessages([
                'one_time_code' => __('two_factor.profile.invalid_code'),
            ])->errorBag('twoFactorConfirm');
        }

        $request->session()->forget(self::ENROLMENT_SESSION_KEY);
        $request->session()->put(
            self::RECOVERY_CODES_SESSION_KEY,
            Crypt::encryptString(json_encode($recoveryCodes, JSON_THROW_ON_ERROR)),
        );

        return redirect()
            ->route('dashboard.profile.show')
            ->with('status', 'two_factor.flash.enabled');
    }

    public function cancel(Request $request): RedirectResponse
    {
        $request->session()->forget(self::ENROLMENT_SESSION_KEY);

        return redirect()
            ->route('dashboard.profile.show')
            ->with('status', 'two_factor.flash.enrolment_cancelled');
    }

    public function regenerate(Request $request, TwoFactorAuthentication $twoFactor): RedirectResponse
    {
        $validated = $request->validateWithBag('twoFactorRecovery', [
            'current_password' => ['required', 'current_password'],
            'one_time_code' => ['required', 'string', 'max:32'],
        ]);

        if (! $twoFactor->verify($request->user(), $validated['one_time_code'])) {
            throw ValidationException::withMessages([
                'one_time_code' => __('two_factor.profile.invalid_code'),
            ])->errorBag('twoFactorRecovery');
        }

        $recoveryCodes = $twoFactor->regenerateRecoveryCodes($request->user());
        $request->session()->put(
            self::RECOVERY_CODES_SESSION_KEY,
            Crypt::encryptString(json_encode($recoveryCodes, JSON_THROW_ON_ERROR)),
        );

        return redirect()
            ->route('dashboard.profile.show')
            ->with('status', 'two_factor.flash.recovery_regenerated');
    }

    public function disable(Request $request, TwoFactorAuthentication $twoFactor): RedirectResponse
    {
        if ($request->user()->account?->requires_two_factor) {
            throw ValidationException::withMessages([
                'current_password' => __('two_factor.profile.required_cannot_disable'),
            ])->errorBag('twoFactorDisable');
        }

        $validated = $request->validateWithBag('twoFactorDisable', [
            'current_password' => ['required', 'current_password'],
            'one_time_code' => ['required', 'string', 'max:32'],
        ]);

        if (! $twoFactor->verify($request->user(), $validated['one_time_code'])) {
            throw ValidationException::withMessages([
                'one_time_code' => __('two_factor.profile.invalid_code'),
            ])->errorBag('twoFactorDisable');
        }

        if (! $twoFactor->disable($request->user())) {
            throw ValidationException::withMessages([
                'current_password' => __('two_factor.profile.required_cannot_disable'),
            ])->errorBag('twoFactorDisable');
        }

        return redirect()
            ->route('dashboard.profile.show')
            ->with('status', 'two_factor.flash.disabled');
    }

    public function pendingSecret(Request $request): ?string
    {
        $encrypted = $request->session()->get(self::ENROLMENT_SESSION_KEY);

        if (! is_string($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            $request->session()->forget(self::ENROLMENT_SESSION_KEY);

            return null;
        }
    }
}
