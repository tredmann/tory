<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('users:make-admin {email : E-Mail-Adresse des Nutzers}')]
#[Description('Macht einen bestehenden Nutzer zum Administrator')]
class MakeUserAdmin extends Command
{
    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->firstWhere('email', $email);

        if ($user === null) {
            $this->error("Kein Nutzer mit der E-Mail {$email}.");

            return self::FAILURE;
        }

        $user->assignRole(UserRole::Admin);
        $this->info("{$user->name} ist jetzt Administrator.");

        return self::SUCCESS;
    }
}
