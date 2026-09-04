<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Creates (or promotes) an operator/admin account for the dashboard.
 *
 * There is no public registration, so this command is the only supported way
 * to create the first admin. The password is read from a hidden prompt and is
 * never accepted as a command argument (which would leak it into shell history
 * and process listings). The password is hashed by the User model cast; the
 * plaintext is never logged or echoed.
 */
class MakeAdminUser extends Command
{
    protected $signature = 'sanad:make-admin
        {--name= : The admin display name}
        {--email= : The admin email (login identifier)}';

    protected $description = 'Create or promote a dashboard admin user (password entered securely)';

    public function handle(): int
    {
        $name = (string) ($this->option('name') ?? $this->ask('Name'));
        $email = (string) ($this->option('email') ?? $this->ask('Email'));

        // Hidden input — never echoed, never taken as an argument.
        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');

        $existing = User::query()->where('email', $email)->first();

        $validator = Validator::make(
            [
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'password_confirmation' => $confirmation,
            ],
            [
                'name' => ['required', 'string', 'max:255'],
                // Ignore the existing row so an existing account can be promoted.
                'email' => [
                    'required', 'email', 'max:255',
                    'unique:users,email'.($existing !== null ? ','.$existing->id : ''),
                ],
                'password' => ['required', 'string', 'min:12', 'confirmed'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if ($existing !== null) {
            $existing->forceFill([
                'name' => $name,
                'password' => $password, // hashed by the model cast
                'status' => UserStatus::Active,
                'is_admin' => true,
            ])->save();

            $this->info("Existing user {$email} promoted to admin.");

            return self::SUCCESS;
        }

        User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password, // hashed by the model cast
            'status' => UserStatus::Active,
            'is_admin' => true,
        ]);

        $this->info("Admin user {$email} created.");

        return self::SUCCESS;
    }
}
