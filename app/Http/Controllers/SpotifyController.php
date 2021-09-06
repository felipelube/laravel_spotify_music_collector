<?php

namespace App\Http\Controllers;

use App\Services\SpotifyService;
use Exception;
use Illuminate\Http\Request;

class SpotifyController extends Controller
{

    public function login(Request $request, SpotifyService $spotifyService) {
        try {
            $code = $request->query->get('code');

            $accessToken = $spotifyService->provider->getAccessToken('authorization_code', [
                'code' => $code
            ]);

            cache()->put('spotify_access_token', $accessToken);
            return redirect("/");
        } catch(Exception $e) {
            return view('login');
        }
    }

    public function request_user_authorization(Request $request, SpotifyService $spotifyService) {
        $code = $request->query->get('code');
        $state = $request->query->get('state');

        if (empty($code)) {
            $authUrl = $spotifyService->provider->getAuthorizationUrl([
                'scope' => SpotifyService::DEFAULT_SCOPES
            ]);

            $request->session()->put("oauth2state", $spotifyService->provider->getState());

            return redirect($authUrl);

        } elseif (empty($state) || ($state !== $request->session()->get("oauth2state", null))) {
            $request->session()->forget("oauth2state");
            $request->session()->flash("Invalid data");

            return view('login');
        }
    }
}
