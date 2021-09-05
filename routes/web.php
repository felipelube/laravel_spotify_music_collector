<?php

use Illuminate\Support\Facades\Route;
use Kerox\OAuth2\Client\Provider\Spotify;

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

Route::get('/', function () {
    return view('welcome');
});

Route::get('/connect/spotify', function () use ($provider) {

    // Optional: Now you have a token you can look up a users profile data4
    try {
        // Try to get an access token (using the authorization code grant)
        $token = $provider->getAccessToken('authorization_code', [
            'code' => $_GET['code']
        ]);

        // We got an access token, let's now get the user's details
        /** @var \Kerox\OAuth2\Client\Provider\SpotifyResourceOwner $user */
        $user = $provider->getResourceOwner($token);

        // Use these details to create a new profile
        printf('Hello %s!', $user->getDisplayName());

        echo '<pre>';
        var_dump($user);
        echo '</pre>';

    } catch (Exception $e) {

        // Failed to get user details
        return view('login');
    }

    echo '<pre>';
    // Use this to interact with an API on the users behalf
    var_dump($token->getToken());
    # string(217) "CAADAppfn3msBAI7tZBLWg...

    // The time (in epoch time) when an access token will expire
    var_dump($token->getExpires());
    # int(1436825866)
    echo '</pre>';


});

Route::post('/connect/spotify', function(Request $request) use ($provider) {
    if (!isset($_GET['code'])) {
        // If we don't have an authorization code then get one
        $authUrl = $provider->getAuthorizationUrl([
            'scope' => [
                Kerox\OAuth2\Client\Provider\Spotify::SCOPE_USER_READ_EMAIL,
            ]
        ]);

        $_SESSION['oauth2state'] = $provider->getState();

        header('Location: ' . $authUrl);
        exit;

    // Check given state against previously stored one to mitigate CSRF attack
    } elseif (empty($_GET['state']) || ($_GET['state'] !== $_SESSION['oauth2state'])) {

        unset($_SESSION['oauth2state']);
        echo 'Invalid state.';
        exit;

    }
});
