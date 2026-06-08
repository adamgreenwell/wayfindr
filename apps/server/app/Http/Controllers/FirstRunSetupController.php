<?php

namespace App\Http\Controllers;

use App\Enums\AccountRole;
use App\Enums\PlatformRole;
use App\Models\Account;
use App\Models\Site;
use App\Models\User;
use App\Support\FirstRunState;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class FirstRunSetupController extends Controller
{
    public function create(Request $request, FirstRunState $firstRunState): View|RedirectResponse
    {
        if (! $firstRunState->needsSetup()) {
            return $this->redirectAfterSetup($request);
        }

        return view('setup.create', [
            'hasIncompleteBootstrapRecords' => $firstRunState->hasIncompleteBootstrapRecords(),
        ]);
    }

    public function store(Request $request, FirstRunState $firstRunState): RedirectResponse
    {
        if (! $firstRunState->needsSetup()) {
            return $this->redirectAfterSetup($request);
        }

        $validated = $request->validate([
            'account_name' => ['required', 'string', 'max:255'],
            'agent_name' => ['required', 'string', 'max:255'],
            'agent_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)],
            'site_name' => ['required', 'string', 'max:255'],
            'site_domain' => ['nullable', 'string', 'max:255'],
        ]);

        [$agent, $site] = DB::transaction(function () use ($validated): array {
            $accountName = trim($validated['account_name']);
            $agentName = trim($validated['agent_name']);
            $siteName = trim($validated['site_name']);

            $account = Account::query()->oldest('id')->lockForUpdate()->first();

            if ($account) {
                $account->update([
                    'name' => $accountName,
                    'slug' => $this->accountSlug($accountName, $account),
                ]);
            } else {
                $account = Account::query()->create([
                    'name' => $accountName,
                    'slug' => $this->accountSlug($accountName),
                ]);
            }

            $agent = User::query()->create([
                'account_id' => $account->id,
                'account_role' => AccountRole::Owner,
                'platform_role' => PlatformRole::Operator,
                'name' => $agentName,
                'email' => mb_strtolower(trim($validated['agent_email'])),
                'password' => Hash::make($validated['password']),
            ]);

            $site = Site::query()
                ->where('account_id', $account->id)
                ->oldest('id')
                ->lockForUpdate()
                ->first();

            $siteAttributes = [
                'account_id' => $account->id,
                'name' => $siteName,
                'domain' => $this->normalizeDomain($validated['site_domain'] ?? null),
                'settings' => [
                    'mask_selectors' => ['input[type="password"]', '[data-wayfindr-mask]'],
                ],
            ];

            if ($site) {
                $site->update($siteAttributes);
            } else {
                $site = Site::query()->create([
                    ...$siteAttributes,
                    'public_key' => $this->publicKey(),
                ]);
            }

            $site->supportAgents()->syncWithoutDetaching($agent->id);

            return [$agent, $site];
        });

        Auth::login($agent);
        $request->session()->regenerate();

        return redirect()
            ->route('dashboard.sites.show', $site)
            ->withFragment('install-snippet')
            ->with('status', 'Wayfindr is ready. Copy the install snippet to connect your first site.');
    }

    private function redirectAfterSetup(Request $request): RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('dashboard');
        }

        return redirect()->route('login');
    }

    private function accountSlug(string $accountName, ?Account $account = null): string
    {
        $baseSlug = Str::slug($accountName);
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'wayfindr-support';
        $slug = $baseSlug;
        $suffix = 2;

        while (Account::query()
            ->where('slug', $slug)
            ->when($account, fn ($query) => $query->whereKeyNot($account->id))
            ->exists()
        ) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function normalizeDomain(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $url = preg_match('/^https?:\/\//i', $value) === 1 ? $value : "https://{$value}";
        $host = parse_url($url, PHP_URL_HOST);
        $port = parse_url($url, PHP_URL_PORT);

        if (! is_string($host) || $host === '') {
            return mb_strtolower($value);
        }

        return mb_strtolower($host.($port ? ":{$port}" : ''));
    }

    private function publicKey(): string
    {
        do {
            $key = 'site_'.Str::lower(Str::random(32));
        } while (Site::query()->where('public_key', $key)->exists());

        return $key;
    }
}
