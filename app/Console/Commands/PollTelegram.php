<?php

namespace App\Console\Commands;

use App\Services\TelegramCommandHandler;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PollTelegram extends Command
{
    private const LEASE_SECONDS = 90;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'telegram:poll
        {--once : Process one batch of updates and exit}
        {--timeout= : Long-poll timeout in seconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Receive and process WorkOS commands from Telegram';

    /**
     * Execute the console command.
     */
    public function handle(TelegramService $telegram, TelegramCommandHandler $handler): int
    {
        if (! $telegram->isConfigured()) {
            $this->error('TELEGRAM_BOT_TOKEN is not configured.');

            return self::FAILURE;
        }

        if (! $telegram->isEnabled()) {
            $this->warn('Telegram notifications are disabled.');

            return self::SUCCESS;
        }

        if (config('services.telegram.allowed_user_ids', []) === []) {
            $this->warn('TELEGRAM_ALLOWED_USER_IDS is empty; every inbound command will be ignored.');
        }

        $once = (bool) $this->option('once');
        $timeout = (int) ($this->option('timeout') ?: config('services.telegram.poll_timeout', 30));
        $timeout = max(1, min($timeout, 50));

        $leaseKey = 'workos.telegram.poller';
        $leaseId = (string) Str::uuid();
        $leaseAcquired = null;

        try {
            $leaseAcquired = Cache::add($leaseKey, $leaseId, self::LEASE_SECONDS);
        } catch (Throwable $exception) {
            Log::warning('Telegram poller lease is unavailable; continuing without a lease.', [
                'error' => $exception->getMessage(),
            ]);
        }

        if ($leaseAcquired === false) {
            $this->warn('Another Telegram poller is already running.');

            return self::SUCCESS;
        }

        $lastUpdateId = (int) Cache::get('workos.telegram.last_update_id', 0);

        $this->info('Telegram poller started.');

        try {
            while (true) {
                if ($leaseAcquired === true) {
                    try {
                        if (Cache::get($leaseKey) !== $leaseId) {
                            if (! Cache::add($leaseKey, $leaseId, self::LEASE_SECONDS)) {
                                $this->warn('Telegram poller lease was lost to another process; stopping.');

                                break;
                            }
                        } else {
                            Cache::put($leaseKey, $leaseId, self::LEASE_SECONDS);
                        }
                    } catch (Throwable $exception) {
                        Log::warning('Telegram poller lease could not be refreshed.', [
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }

                try {
                    $updates = $telegram->getUpdates(
                        $lastUpdateId > 0 ? $lastUpdateId + 1 : 0,
                        $timeout,
                    );
                } catch (Throwable $exception) {
                    Log::warning('Telegram getUpdates failed.', [
                        'error' => $exception->getMessage(),
                    ]);
                    $this->error($exception->getMessage());

                    if ($once) {
                        return self::FAILURE;
                    }

                    sleep(5);

                    continue;
                }

                foreach ($updates as $update) {
                    if (! is_array($update)) {
                        continue;
                    }

                    $updateId = (int) ($update['update_id'] ?? 0);

                    if ($updateId <= 0 || $updateId <= $lastUpdateId) {
                        continue;
                    }

                    try {
                        $handler->handle($update);
                    } catch (Throwable $exception) {
                        Log::error('Telegram update could not be processed.', [
                            'update_id' => $updateId,
                            'error' => $exception->getMessage(),
                        ]);
                    }

                    $lastUpdateId = $updateId;
                    Cache::forever('workos.telegram.last_update_id', $lastUpdateId);
                }

                if ($once) {
                    break;
                }
            }
        } finally {
            if ($leaseAcquired === true) {
                try {
                    if (Cache::get($leaseKey) === $leaseId) {
                        Cache::forget($leaseKey);
                    }
                } catch (Throwable $exception) {
                    Log::warning('Telegram poller lease could not be released.', [
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return self::SUCCESS;
    }
}
