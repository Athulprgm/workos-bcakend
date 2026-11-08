<?php

namespace App\Services;

use App\Enums\BugPriority;
use App\Enums\BugStatus;
use App\Models\Bug;
use App\Models\GoogleAccount;
use App\Models\GoogleSheetConnection;
use App\Models\SheetSyncConflict;
use App\Models\TesterInvitation;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GoogleSheetsService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API_BASE = 'https://sheets.googleapis.com/v4/spreadsheets';

    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    private const SCOPES = [
        'https://www.googleapis.com/auth/spreadsheets',
        'https://www.googleapis.com/auth/userinfo.email',
    ];

    /**
     * Build the Google consent URL. State is stored server-side and bound to
     * the authenticated user to prevent OAuth CSRF.
     */
    public function getAuthorizationUrl(User $user): string
    {
        $this->ensureConfigured();

        $state = Str::random(64);
        Cache::put('google-oauth-state:'.$state, $user->id, now()->addMinutes(15));

        $params = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return self::AUTH_URL.'?'.$params;
    }

    /**
     * Exchange the authorization code and persist encrypted credentials.
     */
    public function handleCallback(string $code, string $state): GoogleAccount
    {
        $userId = Cache::pull('google-oauth-state:'.$state);

        if (! $userId) {
            throw ValidationException::withMessages([
                'state' => 'The Google authorization request is invalid or has expired. Please connect again.',
            ]);
        }

        $user = User::find($userId);

        if (! $user) {
            throw ValidationException::withMessages([
                'state' => 'The Google authorization request belongs to an unknown account.',
            ]);
        }

        $response = Http::asForm()->acceptJson()->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.google.redirect_uri'),
        ]);

        if (! $response->successful()) {
            Log::warning('Google OAuth token exchange failed', [
                'status' => $response->status(),
                'error' => $response->json('error'),
            ]);

            throw new Exception('Google rejected the authorization code. Please try again.');
        }

        $token = $response->json();
        $accessToken = $token['access_token'] ?? null;

        if (! $accessToken) {
            throw new Exception('Google did not return an access token.');
        }

        $profile = Http::withToken($accessToken)
            ->acceptJson()
            ->get(self::USERINFO_URL);

        if (! $profile->successful()) {
            throw new Exception('Google did not allow reading the connected account profile.');
        }

        $profileData = $profile->json();

        return GoogleAccount::updateOrCreate(
            ['user_id' => $user->id],
            [
                'google_user_id' => isset($profileData['id']) ? (string) $profileData['id'] : null,
                'email' => $profileData['email'] ?? $user->email,
                'access_token' => $accessToken,
                'refresh_token' => $token['refresh_token'] ?? null,
                'token_expires_at' => isset($token['expires_in'])
                    ? now()->addSeconds((int) $token['expires_in'])
                    : null,
                'scopes' => isset($token['scope'])
                    ? array_values(array_filter(explode(' ', $token['scope'])))
                    : self::SCOPES,
            ]
        );
    }

    public function disconnect(User $user): void
    {
        $account = $user->googleAccount;

        if ($account) {
            // Sheet connections belong to the revoked credential; removing
            // them prevents future syncs with a disconnected account.
            $account->sheetConnections()->delete();
            $account->delete();
        }
    }

    /**
     * Parse a Google Sheets URL and return the opaque spreadsheet ID.
     */
    public function parseSpreadsheetUrl(string $url): string
    {
        if (! preg_match('#docs\.google\.com/spreadsheets/d/([a-zA-Z0-9-_]+)#', $url, $matches)) {
            throw ValidationException::withMessages([
                'spreadsheet_url' => 'Paste a valid Google Sheets URL (docs.google.com/spreadsheets/d/…).',
            ]);
        }

        return $matches[1];
    }

    public function fetchSpreadsheet(GoogleAccount $account, string $spreadsheetId): array
    {
        $response = $this->client($this->accessToken($account))
            ->get(self::API_BASE.'/'.rawurlencode($spreadsheetId), [
                'fields' => 'properties.title,sheets.properties(title,sheetId,index)',
            ]);

        $this->assertSuccessful($response, 'The spreadsheet could not be opened with the connected Google account.');

        return $response->json();
    }

    /**
     * Public entry point used by the controller and the scheduled command.
     *
     * @return array<string, mixed>
     */
    public function sync(GoogleSheetConnection $connection): array
    {
        $lock = Cache::lock('google-sheet-sync:'.$connection->id, 120);

        if (! $lock->get()) {
            throw new Exception('A sync for this spreadsheet is already running.');
        }

        try {
            $summary = $this->runSync($connection);
            $connection->update([
                'last_synced_at' => now(),
                'last_sync_status' => $summary['errors'] === [] ? 'success' : 'partial',
                'last_sync_error' => $summary['errors'] === [] ? null : implode(' | ', array_slice($summary['errors'], 0, 5)),
                'last_imported_count' => $summary['created'] + $summary['updated'],
                'last_exported_count' => $summary['exported'],
            ]);

            return $summary;
        } catch (Exception $e) {
            $connection->update([
                'last_sync_status' => 'error',
                'last_sync_error' => Str::limit($e->getMessage(), 2000),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function runSync(GoogleSheetConnection $connection): array
    {
        $account = $connection->googleAccount;

        if (! $account) {
            throw new Exception('The Google account for this connection is no longer connected.');
        }

        $values = $this->readAllValues($account, $connection);
        $headers = $values[0] ?? $this->canonicalHeaders();

        if (($values[0] ?? []) === []) {
            $this->writeHeaderRow($account, $connection);
            $values = [$headers];
        }

        $map = $this->headerMap($headers);
        $headerRow = max(1, (int) $connection->header_row);
        $dataRows = array_slice($values, $headerRow);

        $summary = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'conflicts' => 0,
            'exported' => 0,
            'errors' => [],
        ];

        $rowsByKey = [];

        foreach ($dataRows as $offset => $row) {
            $rowNumber = $headerRow + $offset + 1;

            if (($row[0] ?? '') === '' && count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            try {
                $parsed = $this->parseRow($row, $map, $rowNumber);
                $rowsByKey[$parsed['external_key']] = ['row' => $row, 'row_number' => $rowNumber, 'data' => $parsed];

                if ($parsed['title'] === '') {
                    $summary['skipped']++;

                    continue;
                }

                $result = $this->upsertFromSheet($connection, $parsed, $rowNumber);

                match ($result) {
                    'created' => $summary['created']++,
                    'updated' => $summary['updated']++,
                    'conflict' => $summary['conflicts']++,
                    default => $summary['skipped']++,
                };
            } catch (Exception $e) {
                $summary['errors'][] = "Row {$rowNumber}: ".$e->getMessage();
            }
        }

        if ($connection->bidirectional) {
            $exported = $this->exportLocalBugs($connection, $account, $headers, $map, $values);
            $summary['exported'] = $exported;
        }

        return $summary;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function readAllValues(GoogleAccount $account, GoogleSheetConnection $connection): array
    {
        $range = "'".str_replace("'", "''", $connection->sheet_name)."'!A1:Z2000";
        $url = self::API_BASE.'/'.rawurlencode($connection->spreadsheet_id).'/values/'.rawurlencode($range);

        $response = $this->client($this->accessToken($account))->get($url, [
            'majorDimension' => 'ROWS',
            'valueRenderOption' => 'FORMATTED_VALUE',
        ]);

        $this->assertSuccessful($response, "Could not read sheet \"{$connection->sheet_name}\".");

        return $response->json('values', []);
    }

    /**
     * Import one row, deduplicating against external keys and bug numbers.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function upsertFromSheet(GoogleSheetConnection $connection, array $parsed, int $rowNumber): string
    {
        $existing = Bug::query()
            ->where('project_id', $connection->project_id)
            ->where('source', 'google_sheets')
            ->where('external_key', $parsed['external_key'])
            ->first();

        if (! $existing && $parsed['number'] !== null) {
            $existing = Bug::query()
                ->where('project_id', $connection->project_id)
                ->where('number', $parsed['number'])
                ->first();
        }

        $remoteHash = $this->remoteHash($parsed);

        if (! $existing) {
            $number = $parsed['number'];
            if ($number === null || Bug::where('project_id', $connection->project_id)->where('number', $number)->exists()) {
                $number = Bug::nextNumberFor($connection->project);
            }

            $bug = new Bug([
                'workspace_id' => $connection->project->workspace_id,
                'project_id' => $connection->project_id,
                'number' => $number,
                'title' => $parsed['title'],
                'details' => $parsed['details'],
                'priority' => $parsed['priority'],
                'status' => $parsed['status'],
                'tester_id' => $this->resolveTesterId($connection, $parsed['tester']),
                'tester_name' => $parsed['tester'],
                'created_by' => $connection->user_id,
                'testing_notes' => $parsed['testing_notes'],
                'validated' => $parsed['validated'],
                'validated_by' => $parsed['validated'] ? $connection->user_id : null,
                'validated_at' => $parsed['validated'] ? now() : null,
                'source' => 'google_sheets',
                'external_key' => $parsed['external_key'],
                'external_updated_at' => $parsed['updated_at'],
                'sync_hash' => $remoteHash,
            ]);

            if ($parsed['created_at']) {
                $bug->created_at = $parsed['created_at'];
            }

            $bug->save();

            return 'created';
        }

        if ($existing->sync_hash === $remoteHash) {
            // Row and local record already agree; still attach the external key
            // so a later manual edit is recognised as a local change.
            if (! $existing->external_key) {
                $existing->external_key = $parsed['external_key'];
                $existing->save();
            }

            return 'unchanged';
        }

        $localChanged = $connection->last_synced_at !== null
            && $existing->updated_at !== null
            && $existing->updated_at->gt($connection->last_synced_at);

        $remoteChanged = $parsed['updated_at'] !== null
            && ($existing->external_updated_at === null || $parsed['updated_at']->gt($existing->external_updated_at));

        if ($localChanged) {
            $resolution = $this->conflictResolution($connection);

            if ($resolution === 'sheet_wins') {
                $this->applyRemoteValues($existing, $parsed, $remoteHash);

                return 'updated';
            }

            if ($resolution === 'manual') {
                $this->recordConflict($connection, $existing, $parsed, $rowNumber);

                return 'conflict';
            }

            // Application wins: keep local values and let the export pass push
            // them back to the sheet.
            return 'skipped';
        }

        if (! $remoteChanged) {
            return 'skipped';
        }

        $this->applyRemoteValues($existing, $parsed, $remoteHash);

        return 'updated';
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function applyRemoteValues(Bug $bug, array $parsed, string $remoteHash): void
    {
        $bug->fill([
            'title' => $parsed['title'],
            'details' => $parsed['details'],
            'priority' => $parsed['priority'],
            'status' => $parsed['status'],
            'tester_name' => $parsed['tester'],
            'testing_notes' => $parsed['testing_notes'],
            'validated' => $parsed['validated'],
            'validated_by' => $parsed['validated'] ? ($bug->validated_by ?? $bug->created_by) : null,
            'validated_at' => $parsed['validated'] ? ($bug->validated_at ?? now()) : null,
            'source' => $bug->source === 'manual' ? 'manual' : 'google_sheets',
            'external_key' => $parsed['external_key'],
            'external_updated_at' => $parsed['updated_at'],
            'sync_hash' => $remoteHash,
            'workspace_id' => $bug->workspace_id ?: $bug->project?->workspace_id,
        ]);

        if ($parsed['number'] !== null && ! Bug::where('project_id', $bug->project_id)
            ->where('number', $parsed['number'])
            ->whereKeyNot($bug->id)
            ->exists()) {
            $bug->number = $parsed['number'];
        }

        $bug->save();
    }

    /**
     * Push local changes into the sheet when bidirectional sync is enabled.
     *
     * @param  array<int, string>  $headers
     * @param  array<string, int|null>  $map
     * @param  array<int, array<int, string>>  $allValues
     */
    private function exportLocalBugs(
        GoogleSheetConnection $connection,
        GoogleAccount $account,
        array $headers,
        array $map,
        array $allValues,
    ): int {
        $exported = 0;
        $rowByKey = [];
        $headerRow = max(1, (int) $connection->header_row);

        foreach (array_slice($allValues, $headerRow) as $offset => $row) {
            $parsed = $this->parseRow($row, $map, $headerRow + $offset + 1);
            $rowByKey[$parsed['external_key']] = ['row' => $row, 'row_number' => $headerRow + $offset + 1, 'data' => $parsed];
        }

        $conflictedBugIds = $connection->openConflicts()->pluck('bug_id')->filter()->all();

        $bugs = Bug::query()
            ->where('project_id', $connection->project_id)
            ->orderBy('id')
            ->limit(500)
            ->get();

        foreach ($bugs as $bug) {
            if (in_array($bug->id, $conflictedBugIds, true)) {
                continue;
            }

            $canonical = $this->bugToCanonical($bug);
            $row = $rowByKey[$bug->external_key] ?? null;

            if ($row) {
                if ($this->remoteHash($row['data']) === $bug->sync_hash) {
                    continue;
                }

                $values = $this->mergeRow($row['row'], $headers, $map, $canonical);
                $this->writeRow($account, $connection, $row['row_number'], $values);
                $bug->forceFill(['sync_hash' => $bug->syncHash(), 'external_updated_at' => now()])->save();
                $exported++;

                continue;
            }

            // Do not duplicate a row that already represents the same bug via
            // its human-readable ID or number.
            $alreadyInSheet = collect($rowByKey)->contains(function (array $candidate) use ($bug): bool {
                return $candidate['data']['number'] !== null && (int) $candidate['data']['number'] === (int) $bug->number;
            });

            if ($alreadyInSheet) {
                continue;
            }

            $values = $this->mergeRow([], $headers, $map, $canonical);
            $this->appendRow($account, $connection, $values);
            $bug->forceFill([
                'external_key' => 'hash:'.sha1(
                    strtolower((string) $canonical['title']).'|'
                    .strtolower((string) ($canonical['details'] ?? '')).'|'
                    .strtolower((string) $canonical['created'])
                ),
                'external_updated_at' => now(),
                'sync_hash' => $bug->syncHash(),
            ])->save();
            $exported++;
        }

        return $exported;
    }

    /**
     * @return array<string, mixed>
     */
    private function bugToCanonical(Bug $bug): array
    {
        return [
            'bug_id' => $bug->reference(),
            'number' => (string) $bug->number,
            'project' => $bug->project?->name ?? '',
            'title' => $bug->title,
            'details' => $bug->details ?? '',
            'priority' => $bug->priority?->value ?? 'medium',
            'status' => $bug->status?->value ?? 'open',
            'tester' => $bug->displayTesterName() ?? '',
            'created' => $bug->created_at?->format('Y-m-d H:i:s') ?? '',
            'updated' => $bug->updated_at?->format('Y-m-d H:i:s') ?? '',
            'testing_notes' => $bug->testing_notes ?? '',
            'validated' => $bug->validated ? 'yes' : 'no',
        ];
    }

    /**
     * @param  array<int, string>  $existingRow
     * @param  array<int, string>  $headers
     * @param  array<string, int|null>  $map
     * @param  array<string, mixed>  $canonical
     * @return array<int, string>
     */
    private function mergeRow(array $existingRow, array $headers, array $map, array $canonical): array
    {
        $width = max(count($headers), count($existingRow), 1);
        $values = array_pad(array_values($existingRow), $width, '');

        foreach ($map as $key => $index) {
            if ($index === null) {
                continue;
            }

            $values[$index] = (string) ($canonical[$key] ?? '');
        }

        return $values;
    }

    private function appendRow(GoogleAccount $account, GoogleSheetConnection $connection, array $values): int
    {
        $range = "'".str_replace("'", "''", $connection->sheet_name)."'!A1";
        $url = self::API_BASE.'/'.rawurlencode($connection->spreadsheet_id).'/values/'.rawurlencode($range).':append';

        $response = $this->client($this->accessToken($account))->post($url.'?'.http_build_query([
            'valueInputOption' => 'RAW',
            'insertDataOption' => 'INSERT_ROWS',
        ]), ['values' => [$values]]);

        $this->assertSuccessful($response, 'Could not append a bug row to Google Sheets.');

        $updatedRange = $response->json('updates.updatedRange', '');
        if (preg_match('/!.*?(\d+)(?::|$)/', $updatedRange, $matches)) {
            return (int) $matches[1];
        }

        return count($this->readAllValues($account, $connection)) + 1;
    }

    /**
     * @param  array<int, string>  $values
     */
    private function writeRow(GoogleAccount $account, GoogleSheetConnection $connection, int $rowNumber, array $values): void
    {
        $width = $this->columnLetter(max(1, count($values)));
        $range = "'".str_replace("'", "''", $connection->sheet_name)."'!A{$rowNumber}:{$width}{$rowNumber}";
        $url = self::API_BASE.'/'.rawurlencode($connection->spreadsheet_id).'/values/'.rawurlencode($range);

        $response = $this->client($this->accessToken($account))->put($url.'?'.http_build_query([
            'valueInputOption' => 'RAW',
        ]), ['values' => [$values]]);

        $this->assertSuccessful($response, 'Could not update a bug row in Google Sheets.');
    }

    private function writeHeaderRow(GoogleAccount $account, GoogleSheetConnection $connection): void
    {
        $range = "'".str_replace("'", "''", $connection->sheet_name)."'!A1";
        $url = self::API_BASE.'/'.rawurlencode($connection->spreadsheet_id).'/values/'.rawurlencode($range);

        $response = $this->client($this->accessToken($account))->put($url.'?'.http_build_query([
            'valueInputOption' => 'RAW',
        ]), ['values' => [$this->canonicalHeaders()]]);

        $this->assertSuccessful($response, 'Could not initialise the spreadsheet header row.');
    }

    /**
     * @return array<int, string>
     */
    public function canonicalHeaders(): array
    {
        return [
            'Bug ID', 'Number', 'Project Name', 'Bug Title', 'Bug Details',
            'Priority', 'Status', 'Tester', 'Created Date', 'Updated Date',
            'Testing Notes', 'Validated',
        ];
    }

    /**
     * @param  array<int, string>  $headers
     * @return array<string, int|null>
     */
    public function headerMap(array $headers): array
    {
        $aliases = [
            'bug_id' => ['bugid', 'id', 'bug', 'reference', 'bugreference'],
            'number' => ['number', 'bugnumber', 'bugno', 'no'],
            'project' => ['project', 'projectname'],
            'title' => ['bugtitle', 'title', 'summary', 'issue'],
            'details' => ['bugdetails', 'details', 'description', 'steps', 'reproductionsteps'],
            'priority' => ['priority', 'severity'],
            'status' => ['status', 'state'],
            'tester' => ['tester', 'assignedtester', 'assignedto', 'reporter'],
            'created' => ['createddate', 'createdat', 'created', 'datecreated', 'reportedon'],
            'updated' => ['updateddate', 'updatedat', 'updated', 'lastupdated', 'lastmodified'],
            'testing_notes' => ['testingnotes', 'testnotes', 'notes', 'validationnotes'],
            'validated' => ['validated', 'validation', 'verified', 'isvalidated'],
        ];

        $normalised = array_map(
            fn ($header): string => preg_replace('/[^a-z0-9]/', '', strtolower((string) $header)),
            $headers,
        );

        $map = [];

        foreach ($aliases as $key => $names) {
            $map[$key] = null;

            foreach ($normalised as $index => $header) {
                if (in_array($header, $names, true)) {
                    $map[$key] = $index;
                    break;
                }
            }
        }

        if ($map['title'] === null) {
            throw ValidationException::withMessages([
                'sheet_name' => 'The sheet needs a header row containing at least a "Bug Title" (or Title/Summary) column.',
            ]);
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $row
     * @param  array<string, int|null>  $map
     * @return array<string, mixed>
     */
    private function parseRow(array $row, array $map, int $rowNumber): array
    {
        $value = fn (string $key): ?string => $map[$key] !== null
            ? trim((string) ($row[$map[$key]] ?? ''))
            : null;

        $title = (string) $value('title');
        $details = $value('details');
        $tester = $value('tester');
        $createdRaw = $value('created');
        $updatedRaw = $value('updated');

        $explicitId = $value('bug_id') ?: $value('number');
        $number = null;
        $externalKey = null;

        if ($explicitId !== null && preg_match('/(\d+)/', $explicitId, $matches)) {
            $number = (int) $matches[1];
            $externalKey = 'id:'.$number;
        }

        $externalKey ??= 'hash:'.sha1(strtolower($title).'|'.strtolower((string) $details).'|'.strtolower((string) $createdRaw));

        return [
            'row_number' => $rowNumber,
            'external_key' => $externalKey,
            'number' => $number,
            'title' => $title,
            'details' => $details ?: null,
            'priority' => $this->normalisePriority($value('priority')),
            'status' => $this->normaliseStatus($value('status')),
            'tester' => $tester ?: null,
            'testing_notes' => $value('testing_notes') ?: null,
            'validated' => $this->parseBoolean($value('validated')),
            'created_at' => $this->parseDate($createdRaw),
            'updated_at' => $this->parseDate($updatedRaw),
            'project_name' => $value('project'),
            'raw' => $row,
        ];
    }

    /**
     * Compute the deterministic remote hash once all canonical fields exist.
     *
     * @param  array<string, mixed>  $parsed
     */
    private function remoteHash(array $parsed): string
    {
        return hash('sha256', json_encode([
            'title' => (string) $parsed['title'],
            'details' => (string) ($parsed['details'] ?? ''),
            'priority' => (string) $parsed['priority'],
            'status' => (string) $parsed['status'],
            'tester' => (string) ($parsed['tester'] ?? ''),
            'testing_notes' => (string) ($parsed['testing_notes'] ?? ''),
            'validated' => (bool) $parsed['validated'],
        ], JSON_THROW_ON_ERROR));
    }

    private function normalisePriority(?string $value): string
    {
        $normalised = strtolower(trim((string) $value));

        return match (true) {
            in_array($normalised, ['critical', 'p0', 'blocker', 'urgent'], true) => BugPriority::Critical->value,
            in_array($normalised, ['high', 'p1', 'major'], true) => BugPriority::High->value,
            in_array($normalised, ['low', 'p3', 'minor', 'trivial'], true) => BugPriority::Low->value,
            default => BugPriority::Medium->value,
        };
    }

    private function normaliseStatus(?string $value): string
    {
        $normalised = strtolower(trim((string) $value));

        return match (true) {
            in_array($normalised, ['in progress', 'in_progress', 'inprogress', 'working', 'investigating'], true) => BugStatus::InProgress->value,
            in_array($normalised, ['fixed', 'resolved', 'done'], true) => BugStatus::Fixed->value,
            in_array($normalised, ['retesting', 're-testing', 'retest', 'verifying', 'qa'], true) => BugStatus::Retesting->value,
            in_array($normalised, ['closed', 'verified', 'cancelled', 'canceled'], true) => BugStatus::Closed->value,
            default => BugStatus::Open->value,
        };
    }

    private function parseBoolean(?string $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', 'validated', 'verified'], true);
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Exception) {
            return null;
        }
    }

    private function resolveTesterId(GoogleSheetConnection $connection, ?string $tester): ?int
    {
        if (! $tester) {
            return null;
        }

        $invitation = TesterInvitation::query()
            ->where('owner_id', $connection->user_id)
            ->where(function ($query) use ($tester): void {
                $query->where('email', strtolower($tester))->orWhere('name', $tester);
            })
            ->first();

        return $invitation?->tester_user_id;
    }

    private function recordConflict(
        GoogleSheetConnection $connection,
        Bug $bug,
        array $parsed,
        int $rowNumber,
    ): void {
        $exists = SheetSyncConflict::query()
            ->where('google_sheet_connection_id', $connection->id)
            ->where('bug_id', $bug->id)
            ->where('status', 'open')
            ->exists();

        if ($exists) {
            return;
        }

        SheetSyncConflict::create([
            'google_sheet_connection_id' => $connection->id,
            'bug_id' => $bug->id,
            'row_key' => $parsed['external_key'],
            'local_values' => $this->bugToCanonical($bug),
            'remote_values' => $parsed,
            'fields' => ['title', 'details', 'priority', 'status', 'tester', 'testing_notes', 'validated'],
            'status' => 'open',
        ]);
    }

    private function conflictResolution(GoogleSheetConnection $connection): string
    {
        return in_array($connection->conflict_policy, ['sheet_wins', 'app_wins', 'manual'], true)
            ? $connection->conflict_policy
            : 'manual';
    }

    /**
     * Resolve a stored conflict from the UI.
     */
    public function resolveConflict(SheetSyncConflict $conflict, string $resolution, User $user): SheetSyncConflict
    {
        if ($conflict->status === 'resolved') {
            return $conflict;
        }

        $connection = $conflict->connection;
        $bug = $conflict->bug;

        if ($resolution === 'remote' && $bug && is_array($conflict->remote_values)) {
            $remote = $conflict->remote_values;
            $this->applyRemoteValues($bug, $remote, $this->remoteHash($remote));
        }

        $conflict->update([
            'status' => 'resolved',
            'resolution' => $resolution,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ]);

        return $conflict->fresh();
    }

    /**
     * Resolve the API token, refreshing it when Google says it has expired.
     */
    public function accessToken(GoogleAccount $account): string
    {
        if (! $account->isExpired()) {
            return $account->access_token;
        }

        if (! $account->refresh_token) {
            throw new Exception('The Google authorization has expired. Please reconnect the account.');
        }

        $response = Http::asForm()->acceptJson()->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $account->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new Exception('Google could not refresh the authorization. Please reconnect the account.');
        }

        $account->update([
            'access_token' => $response->json('access_token'),
            'token_expires_at' => now()->addSeconds((int) ($response->json('expires_in') ?? 3600)),
        ]);

        return $account->access_token;
    }

    public function ensureConfigured(): void
    {
        if (empty(config('services.google.client_id')) || empty(config('services.google.client_secret'))) {
            throw ValidationException::withMessages([
                'google' => 'Google OAuth is not configured. Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET on the backend.',
            ]);
        }
    }

    private function client(string $accessToken)
    {
        return Http::baseUrl(self::API_BASE)
            ->withToken($accessToken)
            ->acceptJson()
            ->timeout(20);
    }

    private function assertSuccessful(Response $response, string $message): void
    {
        if ($response->successful()) {
            return;
        }

        if ($response->status() === 401) {
            throw new Exception('The Google authorization is invalid. Please reconnect the account.');
        }

        if ($response->status() === 403) {
            throw new Exception($message.' Google denied access (403). Confirm the spreadsheet is shared with the connected account.');
        }

        if ($response->status() === 404) {
            throw new Exception($message.' The spreadsheet or sheet was not found.');
        }

        throw new Exception($message.' Google responded with HTTP '.$response->status().'.');
    }

    private function columnLetter(int $number): string
    {
        $letters = '';

        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $letters = chr(65 + $remainder).$letters;
            $number = intdiv($number - 1, 26);
        }

        return $letters;
    }
}
