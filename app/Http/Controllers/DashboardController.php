<?php

namespace App\Http\Controllers;

use App\Services\SpotifyService;

class DashboardController extends Controller
{
    function index(SpotifyService $spotifyService) {
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
    }
}
