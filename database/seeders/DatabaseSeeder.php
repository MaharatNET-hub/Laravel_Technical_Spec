<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * The demo keeps no database tables; seeding restores its files instead.
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
