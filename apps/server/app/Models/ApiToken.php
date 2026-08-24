<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A credential that reads an account's support data without a person attached.
 *
 * Hand-rolled rather than Sanctum, per ADR 0018: what is written here is a
 * hashed lookup and an expiry check, not cryptography. Password hashing,
 * sessions and CSRF remain Laravel's.
 *
 * The plaintext exists for exactly one response. Everything stored is derived:
 * a SHA-256 hash to look it up by, and the last four characters so an operator
 * can match a row in the dashboard to the credential in their deployment config
 * without that being enough to use it.
 */
class ApiToken extends Model
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory;

    /** Reads the whole read surface. Deny-by-default: nothing else is implied. */
    public const ABILITY_READ = 'read';

    /** @var list<string> */
    public const ABILITIES = [self::ABILITY_READ];

    /**
     * Recognisable at a glance and to a secret scanner. A credential that
     * announces what it is gets revoked when it leaks; an anonymous blob of
     * base62 sits in a public repository unnoticed.
     */
    public const PREFIX = 'wfk_';

    protected $fillable = [
        'account_id',
        'created_by_id',
        'name',
        'token_hash',
        'last_four',
        'abilities',
        'restricts_sites',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'restricts_sites' => 'boolean',
            'last_used_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * Generate a token, returning the plaintext and the row to store beside it.
     *
     * @return array{plain: string, hash: string, last_four: string}
     */
    public static function generate(): array
    {
        // 40 characters of base62 from a CSPRNG. `Str::random` is
        // `random_bytes` underneath, not `rand`.
        $secret = Str::random(40);
        $plain = self::PREFIX.$secret;

        return [
            'plain' => $plain,
            'hash' => self::hash($plain),
            'last_four' => substr($secret, -4),
        ];
    }

    /**
     * The lookup key for a presented token.
     *
     * SHA-256 rather than bcrypt deliberately, and the reason is not
     * performance: a token is 40 characters of CSPRNG output, so there is no
     * guessable input for a slow hash to protect. Bcrypt would also make the
     * lookup impossible -- every row would have to be tried in turn.
     */
    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Whether a presented credential could be one of ours at all.
     *
     * Every issued token is the prefix followed by exactly 40 base62
     * characters, so anything else cannot be in the table and can be refused
     * without a database read. Shape only -- this says nothing about whether
     * the token exists, belongs to anyone, or still works.
     */
    public static function looksLikeToken(string $presented): bool
    {
        return preg_match('/^'.preg_quote(self::PREFIX, '/').'[A-Za-z0-9]{40}$/', $presented) === 1;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * The sites this token is restricted to, which may be none -- meaning the
     * account's sites rather than no sites.
     */
    public function sites(): BelongsToMany
    {
        return $this->belongsToMany(Site::class, 'api_token_site');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(?CarbonImmutable $at = null): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThanOrEqualTo($at ?? CarbonImmutable::now());
    }

    public function isUsable(?CarbonImmutable $at = null): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired($at);
    }

    /**
     * Deny by default. Named `hasAbility` rather than `can` on purpose: `can`
     * is the Gate contract, and a model that answers it without being wired to
     * a policy invites somebody to assume authorisation has been checked.
     */
    public function hasAbility(string $ability): bool
    {
        return in_array($ability, (array) $this->abilities, true);
    }

    public function displayHint(): string
    {
        return self::PREFIX.'…'.$this->last_four;
    }
}
