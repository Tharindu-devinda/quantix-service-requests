<?php

namespace Database\Factories;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => UserRole::Employee,
            'manager_id' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function employee(): static
    {
        return $this->state(['role' => UserRole::Employee]);
    }

    public function manager(): static
    {
        return $this->state(['role' => UserRole::Manager]);
    }

    public function operations(): static
    {
        return $this->state(['role' => UserRole::Operations]);
    }
}