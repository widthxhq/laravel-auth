<?php

use Illuminate\Support\Facades\Route;
use Widthx\Laravel\Http\Controllers\AuthController;
use Widthx\Laravel\Http\Controllers\BackchannelLogoutController;

$prefix = config('widthx.route_prefix', 'auth/widthx');

Route::middleware('web')->prefix($prefix)->name('widthx.')->group(function () {
    Route::get('login', [AuthController::class, 'login'])->name('login');
    Route::get('callback', [AuthController::class, 'callback'])->name('callback');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');
});

// Server-to-server call from the IdP: no session, no CSRF cookie (authenticated by the signed logout_token).
Route::post("{$prefix}/backchannel-logout", BackchannelLogoutController::class)
    ->middleware('throttle:60,1')
    ->name('widthx.backchannel');
