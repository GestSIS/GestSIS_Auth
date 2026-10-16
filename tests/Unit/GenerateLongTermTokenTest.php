<?php

namespace Tests\Unit;

use App\Models\Organisation;
use Tests\TestCase;

class GenerateLongTermTokenTest extends TestCase
{
    public function testGeneratesATokenForAnExistingOrgKey(): void
    {
        $organisation = Organisation::factory()->create(['org_key' => 'ltt-test']);

        $this->artisan('token:generate-long-term', ['sisKey' => 'ltt-test'])->assertExitCode(0);
    }

    public function testFailsForAnUnknownOrgKey(): void
    {
        $this->artisan('token:generate-long-term', ['sisKey' => 'does-not-exist'])->assertExitCode(1);
    }
}
