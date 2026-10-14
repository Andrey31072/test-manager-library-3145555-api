<?php

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ServiceProvider;
use Ramsey\Uuid\Uuid;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole() && ! str_contains(implode(' ', $_SERVER['argv'] ?? []), 'migrate')) {
            return;
        }

        try {
            $username = strtolower(trim((string) env('ADMIN_USERNAME', 'admin')));
            $password = (string) env('ADMIN_PASSWORD', '');

            if ($username !== '' && $password !== '' && DB::table('library.user')->where('username', $username)->doesntExist()) {
                DB::table('library.user')->insert([
                    'id' => Uuid::uuid4()->toString(),
                    'username' => $username,
                    'password_hash' => Hash::make($password),
                    'role' => 'admin',
                ]);
            }
        } catch (\Throwable) {
            // Database may not be migrated yet. Startup must continue until migrations run.
        }
    }
}
