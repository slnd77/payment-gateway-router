<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

class DemoReset extends Command
{
    protected $signature = 'demo:reset {--force : Run even when DEMO_ENABLED is false}';

    protected $description = 'Wipe the database and reseed the public demo data';

    public function handle(): int
    {
        if (! config('demo.enabled') && ! $this->option('force')) {
            $this->error('Demo mode is off (DEMO_ENABLED=false). Refusing to wipe the database.');

            return self::FAILURE;
        }

        $this->call('migrate:fresh', ['--force' => true]);
        $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);
        $this->call('cache:clear');

        $this->info('Demo data reset.');

        return self::SUCCESS;
    }
}
