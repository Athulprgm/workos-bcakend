<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiMemory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiMemoryController extends Controller
{
    /**
     * Display a listing of user memories.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $workspaceId = $request->input('workspace_id') ?? $request->header('X-Workspace-Id');
        $category = $request->input('category');
        $search = $request->input('search');

        $query = AiMemory::where('user_id', $user->id)
            ->when($workspaceId && $workspaceId !== 'all', function ($q) use ($workspaceId) {
                $q->where(function ($sub) use ($workspaceId) {
                    $sub->where('workspace_id', (int) $workspaceId)
                        ->orWhereNull('workspace_id');
                });
            })
            ->when($category, fn ($q) => $q->byCategory($category))
            ->when($search, fn ($q) => $q->search($search))
            ->orderBy('importance', 'desc')
            ->orderBy('updated_at', 'desc');

        $memories = $query->paginate($request->integer('per_page', 30));

        return response()->json($memories);
    }

    /**
     * Store a newly created or updated memory.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
            'key' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
            'importance' => ['nullable', 'integer', 'between:1,5'],
            'workspace_id' => ['nullable'],
        ]);

        $user = $request->user();
        $rawWsId = $validated['workspace_id'] ?? $request->header('X-Workspace-Id');
        $workspaceId = ($rawWsId && $rawWsId !== 'all') ? (int) $rawWsId : null;

        $memory = null;
        if (! empty($validated['key'])) {
            $memory = AiMemory::where('user_id', $user->id)
                ->where('key', $validated['key'])
                ->when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))
                ->first();
        }

        if ($memory) {
            $memory->update([
                'content' => $validated['content'],
                'category' => $validated['category'] ?? $memory->category,
                'importance' => $validated['importance'] ?? $memory->importance,
                'last_accessed_at' => now(),
            ]);
        } else {
            $memory = AiMemory::create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'key' => $validated['key'] ?? null,
                'content' => $validated['content'],
                'category' => $validated['category'] ?? 'fact',
                'importance' => $validated['importance'] ?? 3,
                'last_accessed_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'Memory saved successfully.',
            'memory' => $memory,
        ], 201);
    }

    /**
     * Display the specified memory.
     */
    public function show(Request $request, AiMemory $memory): JsonResponse
    {
        if ($memory->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $memory->markAccessed();

        return response()->json($memory);
    }

    /**
     * Update the specified memory.
     */
    public function update(Request $request, AiMemory $memory): JsonResponse
    {
        if ($memory->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'content' => ['sometimes', 'string', 'max:2000'],
            'key' => ['nullable', 'string', 'max:120'],
            'category' => ['sometimes', 'string', 'max:60'],
            'importance' => ['sometimes', 'integer', 'between:1,5'],
        ]);

        $memory->update($validated);

        return response()->json([
            'message' => 'Memory updated successfully.',
            'memory' => $memory,
        ]);
    }

    /**
     * Remove the specified memory.
     */
    public function destroy(Request $request, AiMemory $memory): JsonResponse
    {
        if ($memory->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $memory->delete();

        return response()->json(['message' => 'Memory deleted successfully.']);
    }

    /**
     * User-controlled wipe/clear of memories.
     */
    public function clear(Request $request): JsonResponse
    {
        $user = $request->user();
        $category = $request->input('category');
        $workspaceId = $request->input('workspace_id');

        $query = AiMemory::where('user_id', $user->id);

        if ($category) {
            $query->where('category', $category);
        }

        if ($workspaceId && $workspaceId !== 'all') {
            $query->where('workspace_id', (int) $workspaceId);
        }

        $deletedCount = $query->delete();

        return response()->json([
            'message' => "Cleared {$deletedCount} memories.",
            'deleted_count' => $deletedCount,
        ]);
    }

    /**
     * Return structured summary of user preferences and long-term memory.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $workspaceId = $request->input('workspace_id') ?? $request->header('X-Workspace-Id');

        $memories = AiMemory::where('user_id', $user->id)
            ->when($workspaceId && $workspaceId !== 'all', function ($q) use ($workspaceId) {
                $q->where(function ($sub) use ($workspaceId) {
                    $sub->where('workspace_id', (int) $workspaceId)
                        ->orWhereNull('workspace_id');
                });
            })
            ->orderBy('importance', 'desc')
            ->limit(20)
            ->get();

        $preferences = $memories->where('category', 'preference')->values();
        $facts = $memories->where('category', 'fact')->values();
        $projectNotes = $memories->where('category', 'project_context')->values();

        $formattedText = $memories->map(fn ($m) => "- [{$m->category}] {$m->content}")->join("\n");

        return response()->json([
            'total_count' => $memories->count(),
            'preferences' => $preferences,
            'facts' => $facts,
            'project_notes' => $projectNotes,
            'formatted_summary' => $formattedText,
        ]);
    }
}

