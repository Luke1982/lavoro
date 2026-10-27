<?php

namespace App\Console\Commands;

use App\Models\Central\LandlordUser;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateLandlordUser extends Command
{
    protected $signature = 'landlord:user {email} {--name=Beheer} {--password=}';

    protected $description = 'Creates an administrator for the landlord panel';

    public function handle(): int
    {
        $password = $this->option('password') ?: Str::password(16);

        $user = LandlordUser::updateOrCreate(
            ['email' => $this->argument('email')],
            ['name' => $this->option('name'), 'password' => $password],
        );

        $this->info("Landlord administrator: {$user->email}");
        $this->line("  password: {$password}");

        return self::SUCCESS;
    }
}
