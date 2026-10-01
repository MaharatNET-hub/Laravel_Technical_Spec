<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * v1 keeps no tables (DemoSeeder restores its files); v2 seeds its admin and site content.
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
        $this->call(V2Seeder::class);
    }
}
