<?php

return [
    // OIDC client registration for this app (one per environment).
    'issuer' => env('WIDTHX_ISSUER', 'https://accounts.widthx.com'),
    'client_id' => env('WIDTHX_CLIENT_ID'),
    'client_secret' => env('WIDTHX_CLIENT_SECRET'),
    'redirect_uri' => env('WIDTHX_REDIRECT_URI', rtrim((string) env('APP_URL'), '/') . '/auth/widthx/callback'),
    'post_logout_redirect_uri' => env('WIDTHX_POST_LOGOUT_REDIRECT_URI', rtrim((string) env('APP_URL'), '/') . '/'),
    'scopes' => explode(' ', (string) env('WIDTHX_SCOPES', 'openid profile email offline_access')),
    // Access-token audience(s) to request for the APIs this app calls.
    'audience' => array_values(array_filter(explode(' ', (string) env('WIDTHX_AUDIENCE', '')))),

    // Resource-server side: audience this Laravel API accepts in Bearer tokens (widthx.token middleware).
    'api_audience' => env('WIDTHX_API_AUDIENCE'),

    // Routes registered by the package (set to false to register your own).
    'routes' => true,
    'route_prefix' => 'auth/widthx',
    'home' => env('WIDTHX_HOME', '/'),

    // Auth guard accounts are signed in with (null = the app's default guard). The account model is that guard's
    // provider model (give it the HasOidcIdentities trait); its table is never altered, links live in `oidc_identities`.
    // user_model is only needed for a non-Eloquent provider; if set, it must be the guard's model.
    'guard' => env('WIDTHX_GUARD'),
    'user_model' => env('WIDTHX_USER_MODEL'),

    // Schema (tables `oidc_identities` and `oidc_session_links`), in whatever style the app manages it:
    //   default    – the package migration runs with `php artisan migrate`;
    //   customised – `php artisan vendor:publish --tag=widthx-migrations`, edit the copy; the package then skips its own;
    //   app-managed (schema dumps, hand-written SQL) – set this to false and create the tables yourself.
    'run_migrations' => (bool) env('WIDTHX_RUN_MIGRATIONS', true),

    // A Widthx identity that no local account (of the guard's model) is linked to (in oidc_identities):
    //   "create": create the local account on first sign-in (self-service apps);
    //   "link":   refuse; an operator links accounts beforehand (admin consoles, invite-only apps).
    // Any other value refuses as well.
    'provisioning' => env('WIDTHX_PROVISIONING', 'create'),

    // Site memberships (ADR 0006): the site this app belongs to, as declared in the Widthx registry. Tokens then
    // carry the account's roles on it (`SiteRoles`, `widthx.role:…`, `@can('widthx-role', '…')`); a token for
    // another site is refused. With a site set, web requests refresh the sign-in when the access token expires so
    // role changes made at Widthx reach the app (refresh_session=false to add `widthx.fresh` to routes yourself).
    'site' => env('WIDTHX_SITE'),
    'refresh_session' => (bool) env('WIDTHX_REFRESH_SESSION', true),

    // Copy email and name from the ID token on every sign-in. Disable when the app owns these fields;
    // a newly created account always gets them.
    'sync_profile' => (bool) env('WIDTHX_SYNC_PROFILE', true),
];
