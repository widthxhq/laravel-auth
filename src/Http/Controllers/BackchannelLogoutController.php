<?php

declare(strict_types=1);

namespace Widthx\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Widthx\Auth\Exception\WidthxAuthException;
use Widthx\Auth\Widthx;
use Widthx\Laravel\SessionLinks;

/** OIDC Back-Channel Logout receiver: ends local sessions for the IdP session (sid) or subject (sub). */
final class BackchannelLogoutController
{
    public function __invoke(Request $request, Widthx $widthx): Response
    {
        try {
            $claims = $widthx->logoutTokens()->verify((string) $request->input('logout_token', ''));
        } catch (WidthxAuthException $e) {
            Log::warning('widthx back-channel logout rejected', ['reason' => $e->getMessage()]);
            return response('', 400)->header('Cache-Control', 'no-store');
        }
        $sessions = SessionLinks::pull($claims['sid'], (string) $claims['sub']);
        $handler = app('session')->driver()->getHandler();
        foreach ($sessions as $sessionId) {
            $handler->destroy($sessionId);
        }
        Log::info('widthx back-channel logout', ['sessions' => count($sessions)]);
        return response('', 200)->header('Cache-Control', 'no-store');
    }
}
