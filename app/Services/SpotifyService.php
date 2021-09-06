<?php

namespace App\Services;

use Kerox\OAuth2\Client\Provider\Spotify;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class SpotifyService {

  /**
   * @var Kerox\OAuth2\Client\Provider\Spotify
   */
  public $provider;

  public const DEFAULT_SCOPES = [
      Spotify::SCOPE_USER_READ_RECENTLY_PLAYED,
      Spotify::SCOPE_PLAYLIST_MODIFY_PRIVATE,
      Spotify::SCOPE_PLAYLIST_READ_PRIVATE,
      Spotify::SCOPE_USER_LIBRARY_MODIFY,
      Spotify::SCOPE_USER_READ_EMAIL,
      Spotify::SCOPE_USER_LIBRARY_READ,
      Spotify::SCOPE_PLAYLIST_MODIFY_PUBLIC,
  ];

  function __construct() {
    $this->provider = new Spotify([
        'clientId'     => env("SPOTIFY_CLIENT_ID"),
        'clientSecret' => env("SPOTIFY_CLIENT_SECRET"),
        'redirectUri'  => env("SPOTIFY_REDIRECT_URL"),
    ]);
  }

  function getUserProfile($accessToken) {
    return Http::withToken($accessToken->getToken())
      ->get('https://api.spotify.com/v1/me')->json();
  }

  public function getAccessToken() {
    $accessToken = cache("spotify_access_token");
    if (!empty($accessToken)) {
      try {
        if ($accessToken->hasExpired()) {
          $newAccessToken = $this->provider->getAccessToken("refresh_token", [
            "refresh_token" => $accessToken->getRefreshToken()
          ]);
          $this->setAccessToken($newAccessToken);
        }
        return $accessToken;
      } catch(Exception $e) {
        return null;
      }
    }
    return null;
  }

  private function setAccessToken(AccessToken $accessToken) {
    cache()->put("spotify_access_token", $accessToken);
  }

  function setAccessTokenFromCode($code) {
    $this->setAccessToken($this->provider->getAccessToken('authorization_code', [
        'code' => $code
    ]));
  }
    $playlists = [];
    $nextUrl = 'https://api.spotify.com/v1/me/playlists';
    do {
        $response = Http::withToken($accessToken->getToken())
          ->get($nextUrl)
          ->json();
        $playlists = array_merge($playlists, $response["items"]);
        $nextUrl = $response["next"];

    } while(!empty($nextUrl));
    //cache()->put("user_playlists", $playlists, now()->addMinutes(60));
    return $playlists;
  }

  function getLastTracks($accessToken, $maxQuantity = 200) {
    $tracks = [];
    $nextUrl = 'https://api.spotify.com/v1/me/tracks';
    do {
        $response = Http::withToken($accessToken->getToken())
          ->get($nextUrl);
        $tracks = array_merge($tracks, $response->json()["items"]);
        $nextUrl = $response["next"];
    } while(!empty($nextUrl) && sizeof($tracks) <= $maxQuantity);
    return $tracks;
  }

  function getDateTimePlaylists($userPlaylists) {
    return array_reduce($userPlaylists, function($dateTimePlaylists, $playlist) {
      if (preg_match("/^\w+\s\d{4}$/", $playlist["name"])) {
          $dateTimePlaylists[$playlist["name"]] = $playlist;
      }
      return $dateTimePlaylists;
    }, []);
  }

  function getLastTracksAsPlaylists($lastTracks) {
    return array_reduce($lastTracks, function($last_tracks_by_month_and_year, $track) {
        $added_date = \DateTime::createFromFormat(\DateTime::RFC3339, $track['added_at']);
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
  }

  function createPlaylistsForLastTracks($accessToken, $playlistsOfLastTracks, $dateTimePlaylists, $userProfile) {
    foreach ($playlistsOfLastTracks as $playlist_name => $tracks) {
        $uris = array_map(function($track) {
            return $track["track"]["uri"];
        }, $tracks);
        $userProfileId = $userProfile["id"];

        if (array_key_exists($playlist_name, $dateTimePlaylists)) {
            $existing_playlist = $dateTimePlaylists[$playlist_name];
            $playlist_id = $existing_playlist["id"];

            Http::withToken($accessToken->getToken())->put("https://api.spotify.com/v1/playlists/$playlist_id/tracks", [
                'uris' => $uris
            ]);
        } else {
            $response = Http::withToken($accessToken->getToken())->post("https://api.spotify.com/v1/users/$userProfileId/playlists", [
                'name' => $playlist_name,
            ]);
            $created_playlist = $response->json();
            $created_playlist_id = $created_playlist["id"];
            //cache()->forget("user_playlists");
            Http::withToken($accessToken->getToken())->put("https://api.spotify.com/v1/playlists/$created_playlist_id/tracks", [
                'uris' => $uris
            ]);
        }
    }
  }

}