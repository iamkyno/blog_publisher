<?php

namespace App\Services;

use App\Exceptions\QuotaExceededException;
use App\Exceptions\YouTubeAuthException;
use App\Models\YoutubeAccount;
use Carbon\CarbonImmutable;
use Google\Client;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\YouTube;
use Illuminate\Support\Facades\Cache;

class YouTubeClient
{
    public const SCOPES = [
        YouTube::YOUTUBE_UPLOAD,   // uploads, thumbnails
        YouTube::YOUTUBE_READONLY, // processing status, channels.list
    ];

    /** A bare OAuth client for the connect flow. */
    public function oauthClient(): Client
    {
        $client = new Client;
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));
        $client->setRedirectUri(config('services.google.redirect') ?: route('oauth.google.callback'));
        $client->setScopes(self::SCOPES);
        $client->setAccessType('offline'); // returns a refresh token
        $client->setPrompt('consent');     // ...every time, even on reconnect
        $client->setIncludeGrantedScopes(true);

        return $client;
    }

    /** An authorised YouTube service for this account. */
    public function for(YoutubeAccount $account): YouTube
    {
        if (! $account->isConnected()) {
            throw new YouTubeAuthException("Account {$account->id} is disconnected; reconnect it in the admin panel.");
        }

        $client = $this->oauthClient();

        $token = Cache::get($account->tokenCacheKey());
        if (! $token) {
            $token = $client->fetchAccessTokenWithRefreshToken($account->refresh_token);
            if (isset($token['error'])) {
                $account->markDisconnected($token['error']);
                throw new YouTubeAuthException($token['error']);
            }
            // Access tokens last ~60 minutes; cache for 55.
            Cache::put($account->tokenCacheKey(), $token, 3300);
        }

        $client->setAccessToken($token);

        return new YouTube($client);
    }

    /** True when a Google API error is a quota error. */
    public static function isQuotaError(GoogleServiceException $e): bool
    {
        foreach ($e->getErrors() ?? [] as $error) {
            if (in_array($error['reason'] ?? null, ['quotaExceeded', 'uploadLimitExceeded', 'dailyLimitExceeded', 'rateLimitExceeded'], true)) {
                return true;
            }
        }

        return false;
    }

    public static function throwIfQuota(GoogleServiceException $e): void
    {
        if (self::isQuotaError($e)) {
            throw new QuotaExceededException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /** Seconds until the daily quota resets (midnight Pacific time). */
    public static function secondsUntilQuotaReset(): int
    {
        $now = CarbonImmutable::now('America/Los_Angeles');

        return (int) $now->diffInSeconds($now->addDay()->startOfDay()) + 60;
    }
}
