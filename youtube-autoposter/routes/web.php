<?php

use App\Http\Controllers\GoogleOAuthController;
use App\Http\Controllers\VideoMediaController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware('auth')->group(function () {
    Route::get('/oauth/google/redirect', [GoogleOAuthController::class, 'redirect'])->name('oauth.google.redirect');
    Route::get('/oauth/google/callback', [GoogleOAuthController::class, 'callback'])->name('oauth.google.callback');

    Route::get('/media/{video}/{kind}/{scene?}', VideoMediaController::class)
        ->whereIn('kind', ['video', 'thumbnail', 'captions', 'scene-audio', 'scene-asset'])
        ->whereNumber('scene')
        ->name('video.media');
});
