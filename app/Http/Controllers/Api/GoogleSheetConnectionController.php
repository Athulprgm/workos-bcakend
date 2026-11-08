<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GoogleSheetConnectionResource;
use App\Models\GoogleSheetConnection;
use App\Models\Project;
use App\Models\SheetSyncConflict;
use App\Services\GoogleSheetsService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GoogleSheetConnectionController extends Controller
{
    public function __construct(protected GoogleSheetsService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $connections = $request->user()->sheetConnections()
            ->with('project')
            ->withCount('openConflicts')
            ->orderByDesc('id')
            ->get();

        return GoogleSheetConnectionResource::collection($connections);
    }

    /**
     * Validate a pasted URL and list the spreadsheet's sheets before connecting.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'spreadsheet_url' => ['required', 'string', 'max:2048'],
        ]);

        $account = $request->user()->googleAccount;

        if (! $account) {
            throw ValidationException::withMessages([
                'google' => 'Connect a Google account before adding a spreadsheet.',
            ]);
        }

        $spreadsheetId = $this->service->parseSpreadsheetUrl($data['spreadsheet_url']);

        try {
            $meta = $this->service->fetchSpreadsheet($account, $spreadsheetId);
        } catch (Exception $e) {
            throw ValidationException::withMessages(['spreadsheet_url' => $e->getMessage()]);
        }

        $sheets = $this->sheetsFromMeta($meta);

        return response()->json([
            'data' => [
                'spreadsheet_id' => $spreadsheetId,
                'title' => $meta['properties']['title'] ?? 'Untitled spreadsheet',
                'sheets' => $sheets,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'spreadsheet_url' => ['required', 'string', 'max:2048'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'sheet_name' => ['required', 'string', 'max:191'],
            'header_row' => ['nullable', 'integer', 'min:1', 'max:20'],
            'bidirectional' => ['nullable', 'boolean'],
            'conflict_policy' => ['nullable', Rule::in(['manual', 'sheet_wins', 'app_wins'])],
        ]);

        $user = $request->user();
        $project = Project::findOrFail($data['project_id']);

        if ((int) $project->user_id !== (int) $user->id) {
            abort(403, 'You are not authorized to connect a spreadsheet to this project.');
        }

        $account = $user->googleAccount;

        if (! $account) {
            throw ValidationException::withMessages([
                'google' => 'Connect a Google account before adding a spreadsheet.',
            ]);
        }

        $spreadsheetId = $this->service->parseSpreadsheetUrl($data['spreadsheet_url']);

        try {
            $meta = $this->service->fetchSpreadsheet($account, $spreadsheetId);
        } catch (Exception $e) {
            throw ValidationException::withMessages(['spreadsheet_url' => $e->getMessage()]);
        }

        $sheets = $this->sheetsFromMeta($meta);

        if (! in_array($data['sheet_name'], $sheets, true)) {
            throw ValidationException::withMessages([
                'sheet_name' => 'That sheet tab does not exist in the spreadsheet.',
            ]);
        }

        $duplicate = GoogleSheetConnection::query()
            ->where('user_id', $user->id)
            ->where('spreadsheet_id', $spreadsheetId)
            ->where('sheet_name', $data['sheet_name'])
            ->where('project_id', $project->id)
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'spreadsheet_url' => 'This sheet is already connected to that project.',
            ]);
        }

        $connection = GoogleSheetConnection::create([
            'user_id' => $user->id,
            'google_account_id' => $account->id,
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->id,
            'spreadsheet_id' => $spreadsheetId,
            'spreadsheet_url' => $data['spreadsheet_url'],
            'sheet_name' => $data['sheet_name'],
            'header_row' => $data['header_row'] ?? 1,
            'sync_enabled' => true,
            'bidirectional' => (bool) ($data['bidirectional'] ?? false),
            'conflict_policy' => $data['conflict_policy'] ?? 'manual',
        ]);

        return GoogleSheetConnectionResource::make($connection->load('project'))
            ->additional(['message' => 'Spreadsheet connected. Run an initial sync to import bugs.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, GoogleSheetConnection $connection): GoogleSheetConnectionResource
    {
        $this->ensureOwner($request, $connection);

        return new GoogleSheetConnectionResource($connection->load('project'));
    }

    public function update(Request $request, GoogleSheetConnection $connection): GoogleSheetConnectionResource
    {
        $this->ensureOwner($request, $connection);

        $data = $request->validate([
            'sync_enabled' => ['sometimes', 'boolean'],
            'bidirectional' => ['sometimes', 'boolean'],
            'conflict_policy' => ['sometimes', Rule::in(['manual', 'sheet_wins', 'app_wins'])],
            'header_row' => ['sometimes', 'integer', 'min:1', 'max:20'],
        ]);

        $connection->update($data);

        return new GoogleSheetConnectionResource($connection->load('project'));
    }

    public function destroy(Request $request, GoogleSheetConnection $connection): Response
    {
        $this->ensureOwner($request, $connection);

        $connection->delete();

        return response()->noContent();
    }

    public function sync(Request $request, GoogleSheetConnection $connection): JsonResponse
    {
        $this->ensureOwner($request, $connection);

        try {
            $summary = $this->service->sync($connection);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'last_sync_status' => $connection->fresh()->last_sync_status,
                'last_sync_error' => $connection->fresh()->last_sync_error,
            ], 422);
        }

        return response()->json([
            'message' => 'Synchronisation finished.',
            'summary' => $summary,
            'data' => new GoogleSheetConnectionResource($connection->fresh()->load('project')),
        ]);
    }

    public function conflicts(Request $request, GoogleSheetConnection $connection): JsonResponse
    {
        $this->ensureOwner($request, $connection);

        $conflicts = $connection->conflicts()
            ->with('bug')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (SheetSyncConflict $conflict): array => [
                'id' => $conflict->id,
                'bug_id' => $conflict->bug_id,
                'bug_reference' => $conflict->bug?->reference(),
                'row_key' => $conflict->row_key,
                'local_values' => $conflict->local_values,
                'remote_values' => $conflict->remote_values,
                'status' => $conflict->status,
                'resolution' => $conflict->resolution,
                'resolved_at' => $conflict->resolved_at?->toIso8601String(),
                'created_at' => $conflict->created_at?->toIso8601String(),
            ]);

        return response()->json(['data' => $conflicts]);
    }

    public function resolveConflict(
        Request $request,
        GoogleSheetConnection $connection,
        SheetSyncConflict $conflict,
    ): JsonResponse {
        $this->ensureOwner($request, $connection);

        if ((int) $conflict->google_sheet_connection_id !== (int) $connection->id) {
            abort(404);
        }

        $data = $request->validate([
            'resolution' => ['required', Rule::in(['local', 'remote'])],
        ]);

        $resolved = $this->service->resolveConflict($conflict, $data['resolution'], $request->user());

        return response()->json([
            'message' => 'Conflict resolved.',
            'data' => [
                'id' => $resolved->id,
                'status' => $resolved->status,
                'resolution' => $resolved->resolution,
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function sheetsFromMeta(array $meta): array
    {
        return collect($meta['sheets'] ?? [])
            ->map(fn ($sheet): ?string => $sheet['properties']['title'] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    private function ensureOwner(Request $request, GoogleSheetConnection $connection): void
    {
        if ((int) $connection->user_id !== (int) $request->user()->id) {
            abort(403, 'You do not own this spreadsheet connection.');
        }
    }
}
