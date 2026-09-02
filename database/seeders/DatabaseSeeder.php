<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Demo data is NEVER seeded automatically in production. It runs only in
     * local/testing environments. To load it explicitly anywhere, run:
     *   php artisan db:seed --class=DemoDataSeeder
     */
    public function run(): void
    {
        if (app()->environment(['local', 'testing'])) {
            $this->call([
                DemoDataSeeder::class,
            ]);
        }
    }
}
