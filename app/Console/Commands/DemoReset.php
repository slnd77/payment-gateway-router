<?php

namespace App\Console\Commands;

use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\WipeCommand;

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

        // AppServiceProvider blocks migrate:fresh in production. Demo mode is
        // the one production setup where wiping the database is the point.
        FreshCommand::prohibit(false);
        WipeCommand::prohibit(false);

        if ($this->call('migrate:fresh', ['--force' => true]) !== self::SUCCESS
            || $this->call('db:seed', ['--class' => DemoSeeder::class, '--force' => true]) !== self::SUCCESS) {
            $this->error('Demo reset failed.');

            return self::FAILURE;
        }

        $this->call('cache:clear');

        $this->info('Demo data reset.');

        return self::SUCCESS;
    }
}
