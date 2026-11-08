<?php

namespace App\Console\Commands;

use App\Models\GoogleSheetConnection;
use App\Services\GoogleSheetsService;
use Illuminate\Console\Command;
use Throwable;

class SyncGoogleSheets extends Command
{
    protected $signature = 'sheets:sync {--connection= : Sync a single connection by ID}';

    protected $description = 'Import and (when enabled) export bug rows for connected Google Sheets';

    public function handle(GoogleSheetsService $service): int
    {
        $query = GoogleSheetConnection::query()->where('sync_enabled', true);

        if ($this->option('connection')) {
            $query->whereKey((int) $this->option('connection'));
        }

        $connections = $query->get();

        if ($connections->isEmpty()) {
            $this->info('No enabled Google Sheets connections to sync.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($connections as $connection) {
            try {
                $summary = $service->sync($connection);
                $this->info(sprintf(
                    'Connection #%d (%s): %d created, %d updated, %d conflicts, %d exported.',
                    $connection->id,
                    $connection->sheet_name,
                    $summary['created'],
                    $summary['updated'],
                    $summary['conflicts'],
                    $summary['exported'],
                ));
            } catch (Throwable $e) {
                $failures++;
                $this->error("Connection #{$connection->id} failed: {$e->getMessage()}");
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
