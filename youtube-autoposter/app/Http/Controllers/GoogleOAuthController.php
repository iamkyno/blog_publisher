<?php

namespace App\Http\Controllers;

use App\Models\YoutubeAccount;
use App\Services\YouTubeClient;
use Google\Service\YouTube;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Connect the channel once; the stored refresh token keeps it signed in. */
class GoogleOAuthController extends Controller
{
    public function redirect(Request $request, YouTubeClient $youtube): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        $client = $youtube->oauthClient();
        $client->setState($state);

        return redirect()->away($client->createAuthUrl());
    }

    public function callback(Request $request, YouTubeClient $youtube): RedirectResponse
    {
        $expected = $request->session()->pull('google_oauth_state');
        abort_unless($expected && hash_equals($expected, (string) $request->query('state')), 403, 'Invalid OAuth state.');

        if ($error = $request->query('error')) {
            return $this->back("Google sign-in was cancelled ({$error}).", 'danger');
        }

        $client = $youtube->oauthClient();
        $token = $client->fetchAccessTokenWithAuthCode((string) $request->query('code'));

        if (isset($token['error'])) {
            return $this->back('Google returned an error: '.($token['error_description'] ?? $token['error']), 'danger');
        }
        if (empty($token['refresh_token'])) {
            return $this->back('Google did not return a refresh token. Remove the app at myaccount.google.com/permissions and connect again.', 'danger');
        }

        $client->setAccessToken($token);
        $channel = (new YouTube($client))->channels->listChannels('id,snippet', ['mine' => true])->getItems()[0] ?? null;

        if (! $channel) {
            return $this->back('That Google account has no YouTube channel.', 'danger');
        }

        $account = YoutubeAccount::updateOrCreate(
            ['channel_id' => $channel->getId()],
            [
                'channel_title' => $channel->getSnippet()->getTitle(),
                'refresh_token' => $token['refresh_token'],
                'status' => 'connected',
                'last_error' => null,
                'connected_at' => now(),
            ],
        );
        Cache::put($account->tokenCacheKey(), $token, 3300);

        return $this->back("Connected {$account->channel_title}.", 'success');
    }

    private function back(string $message, string $status): RedirectResponse
    {
        return redirect('/admin/youtube-accounts')->with('oauth_status', [$status, $message]);
    }
}
