<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TesterInvitationResource;
use App\Models\Project;
use App\Models\TesterInvitation;
use App\Models\User;
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

class TesterInvitationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->ensureOwner($request);

        $invitations = $request->user()->testerInvitations()
            ->with('projects')
            ->orderByDesc('id')
            ->get();

        return TesterInvitationResource::collection($invitations);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureOwner($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('tester_invitations', 'email')->where('owner_id', $request->user()->id),
            ],
            'project_ids' => ['required', 'array', 'min:1'],
            'project_ids.*' => ['integer', 'distinct'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $projects = $this->authorizedProjects($request->user(), $data['project_ids']);
        $accessKey = $this->generateAccessKey();

        $invitation = DB::transaction(function () use ($data, $projects, $accessKey, $request): TesterInvitation {
            $invitation = TesterInvitation::create([
                'owner_id' => $request->user()->id,
                'name' => $data['name'],
                'email' => strtolower(trim($data['email'])),
                'key_hash' => Hash::make($accessKey),
                'key_hint' => substr($accessKey, -4),
                'status' => 'pending',
                'expires_at' => $data['expires_at'] ?? null,
            ]);

            $invitation->projects()->sync($projects->pluck('id')->all());

            return $invitation;
        });

        return TesterInvitationResource::make($invitation->load('projects'))
            ->additional(['access_key' => $accessKey])
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, TesterInvitation $invitation): TesterInvitationResource
    {
        $this->ensureInvitationOwner($request, $invitation);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'project_ids' => ['sometimes', 'array', 'min:1'],
            'project_ids.*' => ['integer', 'distinct'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if (array_key_exists('project_ids', $data)) {
            $projects = $this->authorizedProjects($request->user(), $data['project_ids']);
            $invitation->projects()->sync($projects->pluck('id')->all());
            unset($data['project_ids']);
        }

        if (array_key_exists('expires_at', $data) && $data['expires_at'] !== null) {
            $request->validate(['expires_at' => ['after:now']]);
        }

        $invitation->update($data);

        return new TesterInvitationResource($invitation->load('projects'));
    }

    /**
     * Revoke access immediately. Existing records are preserved for audit.
     */
    public function revoke(Request $request, TesterInvitation $invitation): Response
    {
        $this->ensureInvitationOwner($request, $invitation);

        $this->revokeInvitation($invitation);

        return response()->noContent();
    }

    /**
     * Rotate the access key, invalidating every existing tester session.
     */
    public function rotateKey(Request $request, TesterInvitation $invitation): JsonResponse
    {
        $this->ensureInvitationOwner($request, $invitation);

        if ($invitation->status === 'revoked') {
            throw ValidationException::withMessages([
                'status' => 'Revoked invitations cannot be reactivated. Create a new tester invitation instead.',
            ]);
        }

        $accessKey = $this->generateAccessKey();

        $invitation->update([
            'key_hash' => Hash::make($accessKey),
            'key_hint' => substr($accessKey, -4),
            'status' => $invitation->tester_user_id ? 'active' : 'pending',
        ]);

        $this->revokeTesterTokens($invitation);

        return response()->json([
            'data' => new TesterInvitationResource($invitation->load('projects')),
            'access_key' => $accessKey,
        ]);
    }

    public function destroy(Request $request, TesterInvitation $invitation): Response
    {
        $this->ensureInvitationOwner($request, $invitation);

        $this->revokeInvitation($invitation);

        return response()->noContent();
    }

    private function revokeInvitation(TesterInvitation $invitation): void
    {
        $invitation->update([
            'status' => 'revoked',
            'revoked_at' => now(),
        ]);

        $this->revokeTesterTokens($invitation);
    }

    private function revokeTesterTokens(TesterInvitation $invitation): void
    {
        if (! $invitation->tester_user_id) {
            return;
        }

        $hasOtherAccess = TesterInvitation::query()
            ->where('tester_user_id', $invitation->tester_user_id)
            ->whereKeyNot($invitation->id)
            ->active()
            ->exists();

        if (! $hasOtherAccess) {
            $invitation->tester?->tokens()->delete();
        }
    }

    private function ensureOwner(Request $request): void
    {
        if ($request->user()->isTester()) {
            abort(403, 'Tester accounts cannot manage tester invitations.');
        }
    }

    private function ensureInvitationOwner(Request $request, TesterInvitation $invitation): void
    {
        $this->ensureOwner($request);

        if ((int) $invitation->owner_id !== (int) $request->user()->id) {
            abort(403, 'You do not own this tester invitation.');
        }
    }

    /**
     * @param  array<int, int>  $projectIds
     * @return Collection<int, Project>
     */
    private function authorizedProjects(User $owner, array $projectIds)
    {
        $projects = $owner->projects()->whereIn('id', $projectIds)->get();

        if ($projects->count() !== count(array_unique($projectIds))) {
            throw ValidationException::withMessages([
                'project_ids' => 'Every selected project must belong to your workspace.',
            ]);
        }

        return $projects;
    }

    private function generateAccessKey(): string
    {
        return 'tst_'.Str::lower(Str::random(10)).'-'.Str::lower(Str::random(10)).'-'.Str::lower(Str::random(10));
    }
}
