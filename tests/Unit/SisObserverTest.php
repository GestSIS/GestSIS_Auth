<?php

namespace Tests\Unit;

use App\Models\Sis;
use Tests\TestCase;

class SisObserverTest extends TestCase
{
    public function testCreatingASisCreatesAMirroredOrganisation(): void
    {
        $sis = Sis::create(['nom' => 'Test SIS', 'abreviation' => 'TST', 'api_key' => 'test-obs']);

        $this->assertDatabaseHas('organisations', [
            'id' => $sis->id,
            'org_key' => 'test-obs',
            'nom' => 'Test SIS',
        ]);
    }

    public function testUpdatingASisUpdatesTheMirroredOrganisation(): void
    {
        $sis = Sis::create(['nom' => 'Old', 'abreviation' => 'OLD', 'api_key' => 'test-obs-2']);
        $sis->update(['nom' => 'New']);

        $this->assertDatabaseHas('organisations', ['id' => $sis->id, 'nom' => 'New']);
    }
}
