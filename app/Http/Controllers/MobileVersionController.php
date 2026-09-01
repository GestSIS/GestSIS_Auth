<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MobileVersionController extends Controller
{
    public function latest(): JsonResponse
    {
        $version = Cache::remember('mobile-app:latest-version', now()->addHour(), fn () => $this->fetchLatestVersion());

        return response()->json(['data' => ['version' => $version]]);
    }

    private function fetchLatestVersion(): ?string
    {
        $repo = config('gestsis.mobile_github_repo');
        if (!$repo) {
            return null;
        }

        $response = Http::acceptJson()->timeout(3)->get("https://api.github.com/repos/{$repo}/releases/latest");

        if (!$response->successful()) {
            report(new RuntimeException(
                "Impossible de récupérer la dernière version mobile depuis GitHub (repo={$repo}, status={$response->status()})"
            ));

            return null;
        }

        $tag = $response->json('tag_name');

        return $tag !== null ? ltrim($tag, 'v') : null;
    }
}
