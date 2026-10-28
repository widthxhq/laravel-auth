<?php

declare(strict_types=1);

namespace Widthx\Laravel;

use Illuminate\Support\Facades\DB;

/** Local session ids per IdP session (sid) and subject, so OIDC back-channel logout can end them. */
final class SessionLinks
{
    public const TABLE = 'oidc_session_links';

    public static function record(string $sessionId, ?string $sid, string $subject): void
    {
        DB::table(self::TABLE)->insert([
            'session_id' => $sessionId, 'sid' => $sid, 'subject' => $subject, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function forget(string $sessionId): void
    {
        DB::table(self::TABLE)->where('session_id', $sessionId)->delete();
    }

    /**
     * Removes the links of an IdP session (or, without sid, of every session of the subject).
     *
     * @return list<string> the local session ids to destroy
     */
    public static function pull(?string $sid, string $subject): array
    {
        $links = DB::table(self::TABLE)
            ->when($sid !== null, fn ($q) => $q->where('sid', $sid), fn ($q) => $q->where('subject', $subject))
            ->get(['id', 'session_id']);
        DB::table(self::TABLE)->whereIn('id', $links->pluck('id'))->delete();
        return $links->pluck('session_id')->all();
    }
}
