<?php

namespace Database\Factories;

use App\Models\TelegramCommand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramCommand>
 */
class TelegramCommandFactory extends Factory
{
    protected $model = TelegramCommand::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'update_id' => fake()->unique()->numberBetween(1, 2_000_000_000),
            'user_id' => null,
            'telegram_user_id' => (string) fake()->numberBetween(100_000_000, 999_999_999),
            'chat_id' => (string) fake()->numberBetween(100_000_000, 999_999_999),
            'command' => 'help',
            'arguments' => null,
            'payload' => [],
            'status' => TelegramCommand::STATUS_PROCESSED,
            'response' => 'Help sent.',
            'error' => null,
            'processed_at' => now(),
        ];
    }
}
