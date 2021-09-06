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

    $playlists = cache("user_playlists", function () {
        $playlists = [];
        $nextUrl = 'https://api.spotify.com/v1/me/playlists';
        do {
            $token = cache("spotify_authorization_code");
            $response = Http::withToken($token)->get($nextUrl)->json();
            $playlists = array_merge($playlists, $response["items"]);
            $nextUrl = $response["next"];

        } while(!empty($nextUrl));
        //cache()->put("user_playlists", $playlists, now()->addMinutes(60));
        return $playlists;
    });

    $datetime_playlists = array_reduce($playlists, function($datetime_playlists, $playlist) {
        if (preg_match("/^\w+\s\d{4}$/", $playlist["name"])) {
            $datetime_playlists[$playlist["name"]] = $playlist;
        }
        return $datetime_playlists;
    }, []);

    $last_tracks_by_month_and_year = array_reduce($tracks, function($last_tracks_by_month_and_year, $track) {
        $added_date = DateTime::createFromFormat(DateTime::RFC3339, $track['added_at']);
        if ($added_date) {
            $month_and_year = $added_date->format('F Y');

            if (array_key_exists($month_and_year, $last_tracks_by_month_and_year)) {
                array_push($last_tracks_by_month_and_year[$month_and_year], $track);
            } else {
                $last_tracks_by_month_and_year[$month_and_year] = [$track];
            }
        }
        return $last_tracks_by_month_and_year;
    }, []);

    foreach ($last_tracks_by_month_and_year as $playlist_name => $tracks) {
        $uris = array_map(function($track) {
            return $track["track"]["uri"];
        }, $tracks);
        $profile_id = $profile["id"];

        if (array_key_exists($playlist_name, $datetime_playlists)) {
            $existing_playlist = $datetime_playlists[$playlist_name];
            $playlist_id = $existing_playlist["id"];

            $token = cache("spotify_authorization_code");
            Http::withToken($token)->put("https://api.spotify.com/v1/playlists/$playlist_id/tracks", [
                'uris' => $uris
            ]);
        } else {
            $token = cache("spotify_authorization_code");
            $response = Http::withToken($token)->post("https://api.spotify.com/v1/users/$profile_id/playlists", [
                'name' => $playlist_name,
            ]);
            $created_playlist = $response->json();
            $created_playlist_id = $created_playlist["id"];
            //cache()->forget("user_playlists");
            Http::withToken($token)->put("https://api.spotify.com/v1/playlists/$created_playlist_id/tracks", [
                'uris' => $uris
            ]);
        }
    }

    return view('dashboard', [
        'tracks' => $tracks
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
