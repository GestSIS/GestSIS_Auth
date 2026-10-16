<?php

namespace Tests\Unit;

use App\Models\Domaine;
use App\Models\Organisation;
use App\Services\ActiveIdentitiesFetcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ActiveIdentitiesFetcherTest extends TestCase
{
    public function testFetchesAndMergesMultipleDomainesIntoOneRosterKeyedByOrganisationId(): void
    {
        $eca = Domaine::where('code', 'eca')->firstOrFail();
        $eca->update(['active_identities_url' => 'https://eca.test/identites-actives']);
        $sisOrg = Organisation::factory()->create(['org_key' => 'hs']);
        $ecaOrg = Organisation::factory()->create(['org_key' => 'other-org', 'domaine_id' => $eca->id]);

        Http::fake([
            '*sis.test*' => Http::response(['data' => ['hs' => [1 => 'a@example.com']]]),
            'https://eca.test/identites-actives' => Http::response(['data' => ['other-org' => [2 => 'b@example.com']]]),
        ]);
        Domaine::where('code', 'sis')->update(['active_identities_url' => 'https://sis.test/identites-actives']);

        $result = (new ActiveIdentitiesFetcher())->fetch();

        $this->assertSame('a@example.com', $result['roster'][$sisOrg->id][1]);
        $this->assertSame('b@example.com', $result['roster'][$ecaOrg->id][2]);
        $this->assertTrue($result['failedDomaines']->isEmpty());
    }

    public function testOneDomaineFailingDoesNotBlockAnotherDomainesSuccessfulFetch(): void
    {
        $eca = Domaine::where('code', 'eca')->firstOrFail();
        $eca->update(['active_identities_url' => 'https://eca.test/identites-actives']);
        Domaine::where('code', 'sis')->update(['active_identities_url' => 'https://sis.test/identites-actives']);
        $sisOrg = Organisation::factory()->create(['org_key' => 'hs']);

        Http::fake([
            'https://sis.test/identites-actives' => Http::response(['data' => ['hs' => [1 => 'a@example.com']]]),
            'https://eca.test/identites-actives' => Http::response(['error' => 'boom'], 500),
        ]);
        $this->mock(ExceptionHandler::class, fn($mock) => $mock->shouldReceive('report')->once());

        $result = (new ActiveIdentitiesFetcher())->fetch();

        $this->assertSame('a@example.com', $result['roster'][$sisOrg->id][1]);
        $this->assertCount(1, $result['failedDomaines']);
        $this->assertSame('eca', $result['failedDomaines']->first()->code);
    }

    public function testAThrownConnectionExceptionOnOneDomaineIsIsolatedFromAnother(): void
    {
        $eca = Domaine::where('code', 'eca')->firstOrFail();
        $eca->update(['active_identities_url' => 'https://eca.test/identites-actives']);
        Domaine::where('code', 'sis')->update(['active_identities_url' => 'https://sis.test/identites-actives']);
        $sisOrg = Organisation::factory()->create(['org_key' => 'hs']);

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'eca.test')) {
                throw new ConnectionException('Connection refused');
            }
            return Http::response(['data' => ['hs' => [1 => 'a@example.com']]]);
        });
        $this->mock(ExceptionHandler::class, fn($mock) => $mock->shouldReceive('report')->once());

        $result = (new ActiveIdentitiesFetcher())->fetch();

        $this->assertSame('a@example.com', $result['roster'][$sisOrg->id][1]);
        $this->assertCount(1, $result['failedDomaines']);
    }

    public function testOrgKeyCollisionAcrossTwoDomainesResolvesIndependently(): void
    {
        $eca = Domaine::where('code', 'eca')->firstOrFail();
        $eca->update(['active_identities_url' => 'https://eca.test/identites-actives']);
        Domaine::where('code', 'sis')->update(['active_identities_url' => 'https://sis.test/identites-actives']);
        $sisOrg = Organisation::factory()->create(['org_key' => 'shared-key']);
        $ecaOrg = Organisation::factory()->create(['org_key' => 'shared-key', 'domaine_id' => $eca->id]);

        Http::fake([
            'https://sis.test/identites-actives' => Http::response(['data' => ['shared-key' => [1 => 'sis-person@example.com']]]),
            'https://eca.test/identites-actives' => Http::response(['data' => ['shared-key' => [2 => 'eca-person@example.com']]]),
        ]);

        $result = (new ActiveIdentitiesFetcher())->fetch();

        $this->assertSame('sis-person@example.com', $result['roster'][$sisOrg->id][1]);
        $this->assertSame('eca-person@example.com', $result['roster'][$ecaOrg->id][2]);
    }
}
