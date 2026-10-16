<?php

namespace Tests\Unit;

use Tests\TestCase;

class DomaineTest extends TestCase
{
    public function testSeederCreatesSisDomaine(): void
    {
        $this->seed(\Database\Seeders\DomaineSeeder::class);
        $this->assertDatabaseHas('domaines', ['code' => 'sis']);
        $this->assertDatabaseHas('domaines', ['code' => 'eca']);
    }
}
