<?php

use App\Services\SpotifyService;
use Illuminate\Support\Facades\Route;
use Kerox\OAuth2\Client\Provider\Spotify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const DEFAULT_SCOPES = [
    Spotify::SCOPE_USER_READ_RECENTLY_PLAYED,
    Spotify::SCOPE_PLAYLIST_MODIFY_PRIVATE,
    Spotify::SCOPE_PLAYLIST_READ_PRIVATE,
    Spotify::SCOPE_USER_LIBRARY_MODIFY,
    Spotify::SCOPE_USER_READ_EMAIL,
    Spotify::SCOPE_USER_LIBRARY_READ,
    Spotify::SCOPE_PLAYLIST_MODIFY_PUBLIC,
];

$provider = new Spotify([
    'clientId'     => env("SPOTIFY_CLIENT_ID"),
    'clientSecret' => env("SPOTIFY_CLIENT_SECRET"),
    'redirectUri'  => env("SPOTIFY_REDIRECT_URL"),
]);

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', function (Request $request, SpotifyService $spotifyService) {
    $accessToken = cache("spotify_access_token");
    if (empty($accessToken) || $accessToken->hasExpired()) {
        return redirect('/connect/spotify');
    }

    $lastTracks = cache("spotify_last_200_tracks", function() use ($spotifyService, $accessToken) {
        $tracks = $spotifyService->getLastTracks($accessToken, 200);
        cache()->put("spotify_last_200_tracks", $tracks, now()->addHour(1));
        return $tracks;
    });

    $profile = cache("spotify_profile", function() use($spotifyService, $accessToken) {
        $profile = $spotifyService->getUserProfile($accessToken);
        cache()->put("spotify_profile", $profile, now()->addDay(7));
        return $profile;
    });

    return view('dashboard',[
        'tracks' => $lastTracks,
        'profile' => $profile,
    ]);
});

Route::get('/connect/spotify', function (Request $request) use ($provider) {
    // Optional: Now you have a token you can look up a users profile data4
    try {
        $code = $request->query->get('code');

        $accessToken = $provider->getAccessToken('authorization_code', [
            'code' => $code
        ]);

        cache()->put('spotify_access_token', $accessToken);
        return redirect("/");
    } catch (Exception $e) {

        // Failed to get user details
        return view('login');
    }
});

Route::post('/connect/spotify', function(Request $request) use ($provider) {
    $code = $request->query->get('code');
    $state = $request->query->get('state');

    if (empty($code)) {
        // If we don't have an authorization code then get one
        $authUrl = $provider->getAuthorizationUrl([
            'scope' => DEFAULT_SCOPES
        ]);

        $request->session()->put("oauth2state", $provider->getState());

        return redirect($authUrl);

    // Check given state against previously stored one to mitigate CSRF attack
    } elseif (empty($state) || ($state !== $request->session()->get("oauth2state", null))) {
        $request->session()->forget("oauth2state");
        $request->session()->flash("Invalid data");
        return view('login');
    }
});
