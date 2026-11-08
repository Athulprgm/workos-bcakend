<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\TeamMemberResource;
use App\Models\Project;
use App\Models\TesterInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Super Admin-only team management.
 *
 * Team accounts receive a password and full workspace access. Tester accounts
 * keep the existing access-key model: creating or converting one returns a key
 * exactly once, and it is stored only as a hash.
 */
class TeamController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:150'],
            'role' => ['nullable', Rule::in(UserRole::values())],
        ]);

        $term = $filters['search'] ?? null;

        $members = User::query()
            ->withCount(['projects', 'tasks', 'assignedBugs'])
            ->with(['testerAccessInvitations' => fn ($query) => $query->with('projects')->latest('id')])
            ->when($term, function (Builder $query) use ($term): void {
                $query->where(function (Builder $inner) use ($term): void {
                    $inner->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->where('role', $role))
            ->orderByRaw("case role when 'super_admin' then 0 when 'admin' then 1 when 'developer' then 2 when 'general_manager' then 3 when 'tester' then 4 else 5 end")
            ->orderBy('name')
            ->get();

        return TeamMemberResource::collection($members);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(UserRole::assignableValues())],
            'password' => ['required_unless:role,tester', 'nullable', 'string', 'min:8', 'max:255'],
            'project_ids' => ['sometimes', 'array'],
            'project_ids.*' => ['integer', 'distinct'],
        ]);

        $role = UserRole::from($data['role']);

        if ($role === UserRole::Tester) {
            return $this->createTester($request->user(), $data);
        }

        $member = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $role->value,
            'email_verified_at' => now(),
        ]);

        return response()->json([
            'data' => TeamMemberResource::make($member->loadCount(['projects', 'tasks', 'assignedBugs'])),
            'message' => "{$member->name} was added as {$role->label()}.",
        ], 201);
    }

    public function update(Request $request, User $member): JsonResponse
    {
        $this->ensureEditable($request, $member);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'role' => ['sometimes', Rule::in(UserRole::assignableValues())],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'project_ids' => ['sometimes', 'array'],
            'project_ids.*' => ['integer', 'distinct'],
        ]);

        $targetRole = isset($data['role']) ? UserRole::from($data['role']) : null;

        if ($member->isTester() && $targetRole !== null && $targetRole !== UserRole::Tester && empty($data['password'])) {
            throw ValidationException::withMessages([
                'password' => 'Set a password when converting a tester into a team account.',
            ]);
        }

        if ($targetRole === UserRole::Tester && ! $member->isTester()) {
            return $this->convertToTester($request, $member, $data);
        }

        $accessKey = null;

        DB::transaction(function () use ($member, $data, $targetRole, &$accessKey): void {
            $attributes = [];

            if (array_key_exists('name', $data)) {
                $attributes['name'] = $data['name'];
            }

            if (array_key_exists('active', $data)) {
                $attributes['is_active'] = (bool) $data['active'];
            }

            if ($targetRole !== null && $targetRole !== UserRole::Tester) {
                $attributes['role'] = $targetRole->value;

                if ($member->isTester()) {
                    $this->revokeTesterInvitations($member);
                }
            }

            if (! empty($data['password'])) {
                $attributes['password'] = Hash::make($data['password']);
            }

            $member->update($attributes);

            if (array_key_exists('active', $data) && ! $data['active']) {
                $member->tokens()->delete();
            }

            if (! empty($data['password']) || ($member->isTester() && $targetRole !== null)) {
                $member->tokens()->delete();
            }

            if (! empty($data['project_ids']) && $member->isTester()) {
                $accessKey = $this->syncTesterProjects($member, $data['project_ids']);
            }
        });

        $payload = [
            'data' => TeamMemberResource::make($this->reloadMember($member)),
            'message' => "{$member->name}'s team access was updated.",
        ];

        if ($accessKey !== null) {
            $payload['access_key'] = $accessKey;
        }

        return response()->json($payload);
    }

    public function destroy(Request $request, User $member): Response|JsonResponse
    {
        $this->ensureEditable($request, $member);

        if ($this->hasOwnedData($member)) {
            return response()->json([
                'message' => 'This member owns projects or work history. Deactivate the account instead of deleting it.',
            ], 422);
        }

        DB::transaction(function () use ($member): void {
            $this->revokeTesterInvitations($member);
            $member->tokens()->delete();
            $member->delete();
        });

        return response()->noContent();
    }

    public function rotateTesterKey(Request $request, User $member): JsonResponse
    {
        if (! $member->isTester()) {
            throw ValidationException::withMessages([
                'role' => 'Only tester accounts use access keys.',
            ]);
        }

        $invitation = $member->testerAccessInvitations()
            ->whereIn('status', ['pending', 'active'])
            ->latest('id')
            ->first();

        if (! $invitation) {
            throw ValidationException::withMessages([
                'role' => 'This tester has no active invitation. Convert the account again to issue a new key.',
            ]);
        }

        $accessKey = $this->generateAccessKey();

        $invitation->update([
            'key_hash' => Hash::make($accessKey),
            'key_hint' => substr($accessKey, -4),
            'status' => 'active',
            'revoked_at' => null,
        ]);

        $member->tokens()->delete();

        return response()->json([
            'data' => TeamMemberResource::make($this->reloadMember($member)),
            'access_key' => $accessKey,
            'message' => 'A new tester access key was issued. Existing tester sessions were revoked.',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createTester(User $owner, array $data): JsonResponse
    {
        $existingInvitation = TesterInvitation::query()
            ->where('owner_id', $owner->id)
            ->where('email', $data['email'])
            ->exists();

        if ($existingInvitation) {
            throw ValidationException::withMessages([
                'email' => 'A tester invitation already exists for this email. Manage it from the Testing module.',
            ]);
        }

        $accessKey = $this->generateAccessKey();
        $projects = $this->testerProjects($owner, $data['project_ids'] ?? null);

        [$tester, $invitation] = DB::transaction(function () use ($owner, $data, $accessKey, $projects): array {
            $tester = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                // Testers authenticate with their access key, never a password.
                'password' => Hash::make(Str::random(48)),
                'role' => UserRole::Tester->value,
                'email_verified_at' => now(),
            ]);

            $invitation = TesterInvitation::create([
                'owner_id' => $owner->id,
                'tester_user_id' => $tester->id,
                'name' => $data['name'],
                'email' => $data['email'],
                'key_hash' => Hash::make($accessKey),
                'key_hint' => substr($accessKey, -4),
                'status' => 'active',
            ]);

            $invitation->projects()->sync($projects->pluck('id')->all());

            return [$tester, $invitation];
        });

        return response()->json([
            'data' => TeamMemberResource::make($this->reloadMember($tester)),
            'access_key' => $accessKey,
            'message' => "{$tester->name} was added as a tester. Share the access key once; it cannot be shown again.",
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function convertToTester(Request $request, User $member, array $data): JsonResponse
    {
        $accessKey = $this->generateAccessKey();
        $projects = $this->testerProjects($request->user(), $data['project_ids'] ?? null);

        DB::transaction(function () use ($request, $member, $accessKey, $projects): void {
            $invitation = TesterInvitation::query()
                ->where('owner_id', $request->user()->id)
                ->where('email', $member->email)
                ->first();

            if ($invitation === null) {
                $invitation = TesterInvitation::create([
                    'owner_id' => $request->user()->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'key_hash' => Hash::make($accessKey),
                    'key_hint' => substr($accessKey, -4),
                    'status' => 'active',
                ]);
            } else {
                $invitation->update([
                    'tester_user_id' => $member->id,
                    'name' => $member->name,
                    'key_hash' => Hash::make($accessKey),
                    'key_hint' => substr($accessKey, -4),
                    'status' => 'active',
                    'revoked_at' => null,
                    'expires_at' => null,
                ]);
            }

            $invitation->projects()->sync($projects->pluck('id')->all());

            $member->update([
                'role' => UserRole::Tester->value,
                'password' => Hash::make(Str::random(48)),
            ]);

            $member->tokens()->delete();
        });

        return response()->json([
            'data' => TeamMemberResource::make($this->reloadMember($member)),
            'access_key' => $accessKey,
            'message' => "{$member->name} now has tester-only access. Share the access key once.",
        ]);
    }

    /**
     * @param  array<int, int>|null  $projectIds
     * @return Collection<int, Project>
     */
    private function testerProjects(User $owner, ?array $projectIds): Collection
    {
        if ($projectIds === null) {
            return $owner->projects()->get();
        }

        $unique = array_values(array_unique(array_map('intval', $projectIds)));
        $projects = $owner->projects()->whereIn('id', $unique)->get();

        if ($projects->count() !== count($unique)) {
            throw ValidationException::withMessages([
                'project_ids' => 'Every selected project must belong to your workspace.',
            ]);
        }

        return $projects;
    }

    /**
     * @param  array<int, int>  $projectIds
     */
    private function syncTesterProjects(User $tester, array $projectIds): string
    {
        $invitation = $tester->testerAccessInvitations()
            ->whereIn('status', ['pending', 'active'])
            ->latest('id')
            ->first();

        if ($invitation === null) {
            throw ValidationException::withMessages([
                'project_ids' => 'This tester has no active invitation to update.',
            ]);
        }

        $owner = User::find($invitation->owner_id);

        if ($owner === null) {
            throw ValidationException::withMessages([
                'project_ids' => 'The tester invitation owner no longer exists.',
            ]);
        }

        $projects = $this->testerProjects($owner, $projectIds);
        $invitation->projects()->sync($projects->pluck('id')->all());

        $accessKey = $this->generateAccessKey();
        $invitation->update([
            'key_hash' => Hash::make($accessKey),
            'key_hint' => substr($accessKey, -4),
        ]);

        $tester->tokens()->delete();

        return $accessKey;
    }

    private function reloadMember(User $member): User
    {
        return $member->fresh()
            ->loadCount(['projects', 'tasks', 'assignedBugs'])
            ->load(['testerAccessInvitations' => fn ($query) => $query->with('projects')->latest('id')]);
    }

    private function revokeTesterInvitations(User $member): void
    {
        $member->testerAccessInvitations()
            ->whereIn('status', ['pending', 'active'])
            ->update([
                'status' => 'revoked',
                'revoked_at' => now(),
            ]);
    }

    private function hasOwnedData(User $member): bool
    {
        return $member->projects()->exists()
            || $member->tasks()->exists()
            || $member->workLogs()->exists()
            || $member->workReports()->exists()
            || $member->reportedBugs()->exists();
    }

    private function ensureEditable(Request $request, User $member): void
    {
        if ((int) $request->user()->id === (int) $member->id) {
            abort(403, 'You cannot change your own team access.');
        }

        if ($member->role === 'super_admin' && $request->user()->role !== 'super_admin') {
            abort(403, 'Super Admin accounts cannot be changed from the Team screen.');
        }
    }

    private function generateAccessKey(): string
    {
        return 'tst_'.Str::lower(Str::random(10)).'-'.Str::lower(Str::random(10)).'-'.Str::lower(Str::random(10));
    }
}
