<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The app's own tables (users, admins, ...) are never altered. Idempotent: an app that already created these
// tables itself (schema dump, SQL) can still run it.
return new class extends Migration {
    public function up(): void
    {
        // Links a local account (any model, any guard) to an identity at an OpenID Provider: the ONLY way accounts
        // are matched (never by email). Apps on UUID/ULID keys: publish this migration and change authenticatable_id.
        if (!Schema::hasTable('oidc_identities')) {
            Schema::create('oidc_identities', function (Blueprint $table) {
                $table->id();
                $table->string('issuer');    // the provider, e.g. https://accounts.widthx.com (no trailing slash)
                $table->string('subject');   // the provider's stable user id (ID token `sub`)
                // morphs() without its own index: the account unique below starts with (type, id) and serves lookups.
                $table->string('authenticatable_type');
                $table->unsignedBigInteger('authenticatable_id');
                $table->timestamps();
                // One local account per identity and account type (a person may be both a user and an admin) ...
                $table->unique(['issuer', 'subject', 'authenticatable_type'], 'oidc_identities_subject_unique');
                // ... and at most one identity per provider for each local account.
                $table->unique(['authenticatable_type', 'authenticatable_id', 'issuer'], 'oidc_identities_account_unique');
            });
        }

        // Maps provider session ids (sid) to local session ids, for OIDC back-channel logout.
        if (!Schema::hasTable('oidc_session_links')) {
            Schema::create('oidc_session_links', function (Blueprint $table) {
                $table->id();
                $table->string('session_id')->index();
                $table->string('sid')->nullable()->index();
                $table->string('subject')->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('oidc_session_links');
        Schema::dropIfExists('oidc_identities');
    }
};
