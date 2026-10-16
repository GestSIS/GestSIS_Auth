<?php

namespace Tests\Unit;

use App\Models\Domaine;
use App\Models\TwoFactorPolicy;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TwoFactorPolicyDomaineTest extends TestCase
{
    public function testSisPolicyIsScopedToTheSisDomaine(): void
    {
        $sisDomaineId = Domaine::where('code', 'sis')->firstOrFail()->id;
        $enforcedAt = now()->subDay();

        $policy = TwoFactorPolicy::current($sisDomaineId);
        $policy->enforced_at = $enforcedAt;
        $policy->save();

        $this->assertTrue(TwoFactorPolicy::isEnforced($sisDomaineId));
        $this->assertSame(
            $enforcedAt->toDateTimeString(),
            DB::table('two_factor_policies')->where('domaine_id', $sisDomaineId)->value('enforced_at')
        );
    }

    public function testEcaPolicyIsEnforcedImmediatelyAfterMigration(): void
    {
        $ecaDomaineId = Domaine::where('code', 'eca')->firstOrFail()->id;

        $this->assertTrue(TwoFactorPolicy::isEnforced($ecaDomaineId));
    }
}
