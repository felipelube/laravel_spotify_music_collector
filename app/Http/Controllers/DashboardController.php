<?php

namespace App\Http\Controllers;

use App\Services\SpotifyService;

class DashboardController extends Controller
{
    function index(SpotifyService $spotifyService) {
        $accessToken = $spotifyService->getAccessToken();
        if (empty($accessToken)) {
            return redirect('/connect/spotify');
        }

        $lastTracks = cache("spotify_last_200_tracks", function() use ($spotifyService) {
            $tracks = $spotifyService->getLastTracks(200);
            cache()->put("spotify_last_200_tracks", $tracks, now()->addHour(1));
            return $tracks;
        });

        $profile = cache("spotify_profile", function() use($spotifyService) {
            $profile = $spotifyService->getUserProfile();
            cache()->put("spotify_profile", $profile, now()->addDay(7));
            return $profile;
        });

        return view('dashboard',[
            'tracks' => $lastTracks,
            'profile' => $profile,
        ]);
    }
}
