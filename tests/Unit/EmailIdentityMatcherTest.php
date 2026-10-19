<?php

namespace Tests\Unit;

use App\Models\Domaine;
use App\Models\Organisation;
use App\Services\EmailIdentityMatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmailIdentityMatcherTest extends TestCase
{
    public function testMatchInDomaineReturnsMatchedTrueWhenApiFindsTheEmail(): void
    {
        $sis = Domaine::where('code', 'sis')->firstOrFail();
        Http::fake(['*/api/v2/email-validate*' => Http::response(['data' => ['hs' => 42]])]);

        $result = (new EmailIdentityMatcher())->matchInDomaine($sis, 'someone@example.com');

        $this->assertTrue($result['matched']);
        $this->assertFalse($result['failed']);
    }

    public function testMatchInDomaineReturnsMatchedFalseWhenApiFindsNoSapeur(): void
    {
        $sis = Domaine::where('code', 'sis')->firstOrFail();
        Http::fake(['*/api/v2/email-validate*' => Http::response(['data' => []])]);

        $result = (new EmailIdentityMatcher())->matchInDomaine($sis, 'nobody@example.com');

        $this->assertFalse($result['matched']);
        $this->assertFalse($result['failed']);
    }

    public function testMatchInDomaineReportsAndFailsOnErrorStatus(): void
    {
        $sis = Domaine::where('code', 'sis')->firstOrFail();
        Http::fake(['*/api/v2/email-validate*' => Http::response(['error' => 'boom'], 500)]);
        $this->mock(ExceptionHandler::class, fn($mock) => $mock->shouldReceive('report')->once());

        $result = (new EmailIdentityMatcher())->matchInDomaine($sis, 'someone@example.com');

        $this->assertTrue($result['failed']);
        $this->assertFalse($result['matched']);
    }

    public function testMatchInDomaineReportsAndFailsOnThrownException(): void
    {
        $sis = Domaine::where('code', 'sis')->firstOrFail();
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });
        $this->mock(ExceptionHandler::class, fn($mock) => $mock->shouldReceive('report')->once());

        $result = (new EmailIdentityMatcher())->matchInDomaine($sis, 'someone@example.com');

        $this->assertTrue($result['failed']);
    }

    public function testMatchAcrossAllDomainesAggregatesMatchesFromMultipleDomaines(): void
    {
        $eca = Domaine::where('code', 'eca')->firstOrFail();
        $eca->update(['email_validate_url' => 'https://eca.test/email-validate']);
        Domaine::where('code', 'sis')->update(['email_validate_url' => 'https://sis.test/email-validate']);
        $sisOrg = Organisation::factory()->create(['org_key' => 'hs']);
        $ecaOrg = Organisation::factory()->create(['org_key' => 'other-org', 'domaine_id' => $eca->id]);

        Http::fake([
            'https://sis.test/email-validate*' => Http::response(['data' => ['hs' => 1]]),
            'https://eca.test/email-validate*' => Http::response(['data' => ['other-org' => 2]]),
        ]);

        $result = (new EmailIdentityMatcher())->matchAcrossAllDomaines('someone@example.com');

        $this->assertSame(1, $result['matches'][$sisOrg->id]);
        $this->assertSame(2, $result['matches'][$ecaOrg->id]);
        $this->assertTrue($result['failedDomaines']->isEmpty());
    }

    public function testMatchAcrossAllDomainesIsolatesOneDomainesFailureFromAnothersSuccess(): void
    {
        $eca = Domaine::where('code', 'eca')->firstOrFail();
        $eca->update(['email_validate_url' => 'https://eca.test/email-validate']);
        Domaine::where('code', 'sis')->update(['email_validate_url' => 'https://sis.test/email-validate']);
        $sisOrg = Organisation::factory()->create(['org_key' => 'hs']);

        Http::fake([
            'https://sis.test/email-validate*' => Http::response(['data' => ['hs' => 1]]),
            'https://eca.test/email-validate*' => Http::response(['error' => 'boom'], 500),
        ]);
        $this->mock(ExceptionHandler::class, fn($mock) => $mock->shouldReceive('report')->once());

        $result = (new EmailIdentityMatcher())->matchAcrossAllDomaines('someone@example.com');

        $this->assertSame(1, $result['matches'][$sisOrg->id]);
        $this->assertCount(1, $result['failedDomaines']);
        $this->assertSame('eca', $result['failedDomaines']->first()->code);
    }

    public function testMatchAcrossAllDomainesResolvesOrgKeyCollisionIndependently(): void
    {
        $eca = Domaine::where('code', 'eca')->firstOrFail();
        $eca->update(['email_validate_url' => 'https://eca.test/email-validate']);
        Domaine::where('code', 'sis')->update(['email_validate_url' => 'https://sis.test/email-validate']);
        $sisOrg = Organisation::factory()->create(['org_key' => 'shared-key']);
        $ecaOrg = Organisation::factory()->create(['org_key' => 'shared-key', 'domaine_id' => $eca->id]);

        Http::fake([
            'https://sis.test/email-validate*' => Http::response(['data' => ['shared-key' => 1]]),
            'https://eca.test/email-validate*' => Http::response(['data' => ['shared-key' => 2]]),
        ]);

        $result = (new EmailIdentityMatcher())->matchAcrossAllDomaines('someone@example.com');

        $this->assertSame(1, $result['matches'][$sisOrg->id]);
        $this->assertSame(2, $result['matches'][$ecaOrg->id]);
    }
}
