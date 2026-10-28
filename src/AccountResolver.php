<?php

declare(strict_types=1);

namespace Widthx\Laravel;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;

/**
 * Finds (or, with provisioning=create, creates) the local account of a verified identity. The identity
 * (issuer, subject, account type) is the ONLY key: accounts are never matched by email.
 */
final class AccountResolver
{
    /**
     * @param array<string, mixed> $claims verified ID token claims
     * @throws AccountRefused
     */
    public function resolve(array $claims): Model&Authenticatable
    {
        $model = self::accountModel();
        $issuer = OidcIdentity::normalizeIssuer((string) $claims['iss']);
        $subject = (string) $claims['sub'];

        $link = $this->link($model, $issuer, $subject);
        if ($link !== null) {
            // The account was removed without its links (raw SQL) or is soft-deleted: never re-create it.
            $account = $link->authenticatable ?? throw new AccountRefused('account_unavailable');
            if (config('widthx.sync_profile', true)) {
                $this->syncProfile($account, $claims);
            }
            return $account;
        }
        if (config('widthx.provisioning', 'create') !== 'create') {
            // Invite-only (or a mistyped mode): accounts are linked beforehand, so an unknown identity gets none.
            Log::info('widthx login refused: no linked account', ['subject' => $subject]);
            throw new AccountRefused('not_linked');
        }

        $account = new $model();
        $account->forceFill($this->profile($account, $claims));
        if (Schema::hasColumn($account->getTable(), 'password')) {
            // The app's password column is often NOT NULL; nobody knows this one, sign-in goes through Widthx.
            $account->forceFill(['password' => Hash::make(Str::random(64))]);
        }
        try {
            DB::transaction(function () use ($account, $issuer, $subject) {
                $account->save();
                $account->oidcIdentities()->create(['issuer' => $issuer, 'subject' => $subject]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent first sign-in of the same identity linked its account first: use that one.
            $account = $this->link($model, $issuer, $subject)?->authenticatable;
            if ($account === null) {
                // Otherwise an app column such as users.email is taken by an account that is not linked.
                Log::info('widthx login refused: new account conflicts with an existing one', ['subject' => $subject]);
                throw new AccountRefused('account_conflict');
            }
        }
        return $account;
    }

    /**
     * The model of WIDTHX_GUARD's user provider. WIDTHX_USER_MODEL is only needed for non-Eloquent providers and
     * must otherwise name the same model: a session on one guard holding an id from another table would sign in
     * as a different account.
     *
     * @return class-string<Model&Authenticatable>
     */
    public static function accountModel(): string
    {
        $guard = config('widthx.guard');
        $provider = Auth::guard($guard);
        $provider = method_exists($provider, 'getProvider') ? $provider->getProvider() : null;
        $guardModel = $provider instanceof EloquentUserProvider ? ltrim($provider->getModel(), '\\') : null;
        $configured = config('widthx.user_model') ? ltrim((string) config('widthx.user_model'), '\\') : null;

        if ($configured !== null && $guardModel !== null && $configured !== $guardModel) {
            throw new LogicException("WIDTHX_USER_MODEL ({$configured}) is not the model of the "
                . ($guard ?? 'default') . " guard ({$guardModel}). Remove it or set WIDTHX_GUARD to that model's guard.");
        }
        $model = $configured ?? $guardModel
            ?? throw new LogicException('Set WIDTHX_USER_MODEL: the ' . ($guard ?? 'default') . ' guard has no Eloquent user provider.');
        if (!in_array(HasOidcIdentities::class, class_uses_recursive($model), true)) {
            throw new LogicException("{$model} must use the " . HasOidcIdentities::class . ' trait.');
        }
        return $model;
    }

    /** @param class-string<Model> $model */
    private function link(string $model, string $issuer, string $subject): ?OidcIdentity
    {
        return OidcIdentity::query()
            ->where(['issuer' => $issuer, 'subject' => $subject, 'authenticatable_type' => (new $model())->getMorphClass()])
            ->first();
    }

    /** @return array{email: string, name: string} Informational; forceFill so the app's $fillable need not list them. */
    private function profile(Model $account, array $claims): array
    {
        return [
            'email' => $claims['email'] ?? $account->email ?? ($claims['sub'] . '@widthx.invalid'),
            'name' => $claims['name'] ?? $account->name ?? ($claims['email'] ?? 'Widthx user'),
        ];
    }

    private function syncProfile(Model $account, array $claims): void
    {
        try {
            $account->forceFill($this->profile($account, $claims))->save();
        } catch (UniqueConstraintViolationException) {
            // e.g. the new email belongs to another local account: keep the local profile, still sign in.
            $account->refresh();
            Log::warning('widthx profile sync skipped: conflicts with another account', ['subject' => $claims['sub']]);
        }
    }
}
