# widthx/laravel-auth

Laravel integration for [Widthx Identity](https://accounts.widthx.com): OpenID Connect sign-in (Authorization Code + PKCE),
local and central logout, OIDC back-channel logout and a middleware for Widthx access tokens. Built on
[`widthx/auth`](https://github.com/widthxhq/auth-php). Laravel 12 or 13, PHP 8.2+.

## Install

```bash
composer require widthx/laravel-auth
php artisan migrate
```

```dotenv
WIDTHX_ISSUER=https://accounts.widthx.com
WIDTHX_CLIENT_ID=your-app-production
WIDTHX_CLIENT_SECRET=...            # from your secret store, never committed
SESSION_DRIVER=database             # or redis: back-channel logout destroys sessions from another process
```

Routes (prefix `auth/widthx`, configurable):

| Route | Purpose |
|---|---|
| `GET  /auth/widthx/login?return_to=/path` | Start sign-in |
| `GET  /auth/widthx/callback` | Redirect URI to register for your client |
| `POST /auth/widthx/logout` | `mode=local` (this app) or `mode=central` (also the Widthx session) |
| `POST /auth/widthx/backchannel-logout` | Register as the client's `backchannel_logout_uri` |

Accounts are linked **only** through the `oidc_identities` table (issuer + subject). They are never matched by
email, and the package never alters your own tables. Add the trait to the account model (the model of the guard
you sign in with, see `WIDTHX_GUARD`):

```php
use Widthx\Laravel\HasOidcIdentities;

class User extends Authenticatable
{
    use HasOidcIdentities;   // $user->oidcIdentities
}
```

## Schema: three ways, pick yours

| Your app | Do this |
|---|---|
| Standard migrations | Nothing: `php artisan migrate` creates `oidc_identities` and `oidc_session_links`. |
| Wants to edit the migration (e.g. UUID/ULID keys: change `authenticatable_id`) | `php artisan vendor:publish --tag=widthx-migrations`, edit the copy. The package detects it and skips its own. |
| Manages the schema itself (schema dumps, SQL) | `WIDTHX_RUN_MIGRATIONS=false`, then create the tables below yourself. |

The migration is idempotent: tables that already exist are left alone.

```sql
CREATE TABLE oidc_identities (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  issuer VARCHAR(255) NOT NULL,                 -- e.g. https://accounts.widthx.com (no trailing slash)
  subject VARCHAR(255) NOT NULL,                -- the provider's stable user id (ID token `sub`)
  authenticatable_type VARCHAR(255) NOT NULL,   -- e.g. App\Models\Admin (or its morph alias)
  authenticatable_id BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  UNIQUE KEY oidc_identities_subject_unique (issuer, subject, authenticatable_type),
  UNIQUE KEY oidc_identities_account_unique (authenticatable_type, authenticatable_id, issuer)
);
CREATE TABLE oidc_session_links (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id VARCHAR(255) NOT NULL, sid VARCHAR(255) NULL, subject VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NULL, updated_at TIMESTAMP NULL,
  KEY (session_id), KEY (sid), KEY (subject)
);
```

One person may hold several account types (an app user and an admin): one identity row per type. A local
account holds at most one identity per provider.

## Accounts: self-service or invite-only

| Setting | Default | Meaning |
|---|---|---|
| `WIDTHX_GUARD` | default guard | Guard the account is signed in with. The account model is this guard's provider model. |
| `WIDTHX_USER_MODEL` | the guard's model | Only for a non-Eloquent user provider. If set, it must be the guard's model, otherwise sign-in fails with a configuration error. |
| `WIDTHX_PROVISIONING` | `create` | `create`: first sign-in creates the account (and an unusable random password if the table has a `password` column). `link`: only accounts linked beforehand may sign in; anything else is refused (`widthx_error=not_linked`) |
| `WIDTHX_SYNC_PROFILE` | `true` | Copy email and name from Widthx on every sign-in |

An admin console next to the app's own users, for example:

```dotenv
WIDTHX_GUARD=admin             # its provider's model, e.g. App\Models\Admin, uses HasOidcIdentities
WIDTHX_PROVISIONING=link        # never let a self-registered Widthx account become an admin
WIDTHX_SYNC_PROFILE=false
```

Link an account after checking who the person is (their Widthx subject is shown in the Widthx admin console):

```php
$admin->oidcIdentities()->create(['issuer' => 'https://accounts.widthx.com', 'subject' => $subject]);
```

Only `issuer` and `subject` are fillable; the account is set through the relation. Deleting an account deletes
its links. A soft-deleted account keeps them and cannot sign in.

A refused sign-in redirects to `WIDTHX_HOME` with `widthx_error` in the session:

| `widthx_error` | Cause |
|---|---|
| `not_linked` | No local account is linked to this identity (`link` mode). |
| `account_unavailable` | The linked account no longer exists or is soft-deleted. |
| `account_conflict` | `create` mode could not create the account: a unique column (e.g. `users.email`) is taken by an account that is not linked. Link that account, or resolve the duplicate. |
| other | The OAuth error from Widthx (e.g. `access_denied`), or `login_failed`. |

Logout invalidates the whole Laravel session, so an app that signs in other guards in the same session signs
those out too.

## Site roles (Widthx site memberships)

When the app is a **site** in the Widthx registry, Widthx decides who may sign in and with which roles, and puts
them in every token. Set the site:

```dotenv
WIDTHX_SITE=pharmed-admin
```

```php
use Widthx\Laravel\SiteRoles;

app(SiteRoles::class)->all();                 // ['admin']
app(SiteRoles::class)->has('admin', 'owner'); // any of
Route::get('/settings', …)->middleware('widthx.role:admin');
@can('widthx-role', 'admin') … @endcan
```

- A token whose `site` is not `WIDTHX_SITE` is refused at sign-in (`widthx_error=site_mismatch`).
- Roles change at Widthx without a new sign-in: with `WIDTHX_SITE` set, every `web` request refreshes the
  sign-in once the access token has expired (≤ 15 min) and updates the roles. A refused refresh (access lost,
  account disabled) ends the session (`widthx_error=access_revoked`, or 401 for JSON); if Widthx is unreachable
  the current roles are kept and the refresh is retried. Concurrent requests share one refresh (cache lock), so
  use a cache store that supports locks. `WIDTHX_REFRESH_SESSION=false` leaves it to you (`widthx.fresh` middleware).
- What a role allows is your app's code. Never take roles from the browser.
- APIs: `$request->attributes->get('widthx_token')->rolesFor('pharmed-admin')`.

## Protect an API

```php
Route::get('/api/projects', fn (Request $r) => $r->attributes->get('widthx_token')->subject)
    ->middleware('widthx.token:projects:read');   // WIDTHX_API_AUDIENCE = the audience this API accepts
```

## Development

This package is developed in the Widthx monorepo and mirrored here. Tests: `composer install && vendor/bin/phpunit`
(in the monorepo, `scripts/php-packages.sh test` uses the local `widthx/auth` instead of Packagist).

## License

MIT
