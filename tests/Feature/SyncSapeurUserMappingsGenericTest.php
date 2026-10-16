<?php

namespace Tests\Feature;

use App\Models\Domaine;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncSapeurUserMappingsGenericTest extends TestCase
{
    public function testSyncReadsTheUrlFromTheSisDomaineConfiguration(): void
    {
        Domaine::where('code', 'sis')->update(['active_identities_url' => 'https://api.test/api/v2/identites-actives']);
        Http::fake(['https://api.test/api/v2/identites-actives' => Http::response(['data' => []], 200)]);

        Artisan::call('users:sync-sapeurs');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.test/api/v2/identites-actives');
    }
}
