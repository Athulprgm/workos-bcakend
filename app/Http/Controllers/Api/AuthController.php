<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\TesterInvitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Register a new user - disabled for single-admin personal WorkOS.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        abort(403, 'Registration is disabled on this personal WorkOS instance.');
    }

    /**
     * Exchange credentials for a personal access token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->safe()->only(['email', 'password']);

        $user = User::where('email', $credentials['email'])->first();

        // A single generic message for both failure modes so the response does
        // not reveal whether the email exists. Tester accounts do not have a
        // usable password login: they must present their access key.
        if (! $user || ! $user->isActive() || $user->isTester() || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => 'The provided credentials are incorrect.',
            ]);
        }

        $token = $user->createToken('spa')->plainTextToken;

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Exchange a tester access lock key (with or without email) for a scoped token.
     * No username or password required - authentication uses only the lock key.
     */
    public function testerLogin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'access_key' => ['required', 'string', 'min:6', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $accessKey = trim($data['access_key']);
        $email = ! empty($data['email']) ? strtolower(trim($data['email'])) : null;
        $invitation = null;

        // Query candidates: filter by email if supplied; otherwise filter by key_hint
        $keyHint = substr($accessKey, -4);

        $candidatesQuery = TesterInvitation::query()
            ->whereIn('status', ['pending', 'active'])
            ->whereNull('revoked_at')
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->with('projects');

        if ($email) {
            $candidatesQuery->where('email', $email);
        } else {
            $candidatesQuery->where(function ($q) use ($keyHint) {
                $q->where('key_hint', $keyHint)->orWhereNull('key_hint');
            });
        }

        $candidates = $candidatesQuery->get();

        foreach ($candidates as $candidate) {
            if (Hash::check($accessKey, $candidate->key_hash)) {
                $invitation = $candidate;
                break;
            }
        }

        // If no candidate matched by hint alone and no email was given, check all active invitations
        if (! $invitation && ! $email) {
            $allCandidates = TesterInvitation::query()
                ->whereIn('status', ['pending', 'active'])
                ->whereNull('revoked_at')
                ->where(function ($query): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->with('projects')
                ->get();

            foreach ($allCandidates as $candidate) {
                if (Hash::check($accessKey, $candidate->key_hash)) {
                    $invitation = $candidate;
                    break;
                }
            }
        }

        if (! $invitation) {
            throw ValidationException::withMessages([
                'access_key' => 'The tester lock key is invalid, expired, or has been revoked.',
            ]);
        }

        $email = $invitation->email;

        $user = $invitation->tester_user_id
            ? User::find($invitation->tester_user_id)
            : User::where('email', $email)->first();

        if ($user && ! $user->isActive()) {
            throw ValidationException::withMessages([
                'access_key' => 'This tester account has been deactivated. Contact the workspace owner.',
            ]);
        }

        if ($user && ! $user->isTester()) {
            throw ValidationException::withMessages([
                'access_key' => 'This key is linked to a team account. Please contact the administrator.',
            ]);
        }

        if (! $user) {
            $user = User::create([
                'name' => $invitation->name ?: 'Tester',
                'email' => $email,
                // Testers authenticate only with their access lock key.
                'password' => Hash::make(Str::random(48)),
                'role' => 'tester',
                'email_verified_at' => now(),
            ]);
        }

        $invitation->update([
            'tester_user_id' => $user->id,
            'status' => 'active',
            'last_used_at' => now(),
        ]);

        $token = $user->createToken('tester', ['tester'])->plainTextToken;

        return response()->json([
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
                'projects' => $invitation->projects->map(fn ($project): array => [
                    'id' => $project->id,
                    'name' => $project->name,
                ]),
            ],
        ]);
    }

    /**
     * Revoke the token used for the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        // Only real personal access tokens can be revoked; session-based
        // (transient) tokens have nothing to delete.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * Return the authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'user' => new UserResource($request->user()),
            ],
        ]);
    }
}
