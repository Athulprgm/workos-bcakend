<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceResource;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class WorkspaceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $user->ensureDefaultWorkspaces();

        $includeArchived = filter_var($request->query('include_archived', false), FILTER_VALIDATE_BOOLEAN);

        $workspaces = $user->workspaces()
            ->when(! $includeArchived, fn ($query) => $query->active())
            ->orderBy('id')
            ->get();

        return WorkspaceResource::collection($workspaces);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'company_type' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:32'],
            'settings' => ['nullable', 'array'],
        ]);

        $data['slug'] = Workspace::uniqueSlugFor($user, $data['name']);
        $data['company_type'] ??= 'company';
        $data['color'] ??= '#6366F1';

        $workspace = $user->workspaces()->create($data);

        return WorkspaceResource::make($workspace)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Workspace $workspace): WorkspaceResource
    {
        $this->authorizeAccess($request, $workspace);

        return new WorkspaceResource($workspace);
    }

    public function update(Request $request, Workspace $workspace): WorkspaceResource
    {
        $this->authorizeAccess($request, $workspace);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'company_type' => ['nullable', 'string', 'max:64'],
            'color' => ['nullable', 'string', 'max:32'],
            'settings' => ['nullable', 'array'],
            'archived' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('name', $data) && $data['name'] !== $workspace->name) {
            $data['slug'] = Workspace::uniqueSlugFor($request->user(), $data['name'], $workspace->id);
        }

        if (array_key_exists('archived', $data)) {
            $data['archived_at'] = $data['archived'] ? now() : null;
            unset($data['archived']);
        }

        $workspace->update($data);

        return new WorkspaceResource($workspace);
    }

    public function destroy(Request $request, Workspace $workspace): Response
    {
        $this->authorizeAccess($request, $workspace);

        // Soft archive instead of hard delete to keep audit history safe
        $workspace->update(['archived_at' => now()]);

        return response()->noContent();
    }

    private function authorizeAccess(Request $request, Workspace $workspace): void
    {
        if ($workspace->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized access to this workspace.');
        }
    }
}
