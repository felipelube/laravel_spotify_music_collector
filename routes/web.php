<?php

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
];

$provider = new Spotify([
    'clientId'     => '0291fccdd6ed4d039949c66e52805d9b',
    'clientSecret' => '5d947fb889d344c9ae2848099b247425',
    'redirectUri'  => 'http://localhost:8000/connect/spotify',
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

Route::get('/', function (Request $request) {
    if (empty(cache('spotify_authorization_code')) || empty(cache('spotify_refresh_token')) ) {
        return redirect('/connect/spotify');
    }

    $token = cache("spotify_authorization_code");
    $profile = Http::withToken($token)->get('https://api.spotify.com/v1/me')->json();

    $tracks = cache("spotify_last_200_tracks", function() {
        $tracks = [];
        $nextUrl = 'https://api.spotify.com/v1/me/tracks';
        do {
            $token = cache("spotify_authorization_code");
            $response = Http::withToken($token)->get($nextUrl);
            $tracks = array_merge($tracks, $response->json()["items"]);
            $nextUrl = $response["next"];
        } while(!empty($nextUrl) && sizeof($tracks) <= 200);
        cache()->put("spotify_last_200_tracks", $tracks, now()->addMinutes(60));
        return $tracks;
    });

    return view('dashboard', [
        'tracks' => $tracks
    ]);
});

Route::get('/connect/spotify', function (Request $request) use ($provider) {
    // Optional: Now you have a token you can look up a users profile data4
    try {
        $code = $request->query->get('code');

        $token = $provider->getAccessToken('authorization_code', [
            'code' => $code
        ]);

        $cacheExpirationDate = new DateTime();
        $cacheExpirationDate->setTimestamp($token->getExpires());

        cache()->put('spotify_authorization_code', $token->getToken(), $cacheExpirationDate);
        cache()->put('spotify_refresh_token', $token->getRefreshToken());

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
