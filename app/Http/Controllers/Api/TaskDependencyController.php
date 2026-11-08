<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\StoreTaskDependencyRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Http\JsonResponse;

class TaskDependencyController extends Controller
{
    public function store(StoreTaskDependencyRequest $request, Task $task): JsonResponse
    {
        $dependencyId = (int) $request->validated()['depends_on_task_id'];

        if ($task->dependencies()->whereKey($dependencyId)->exists()) {
            return response()->json(['message' => 'That dependency already exists.'], 409);
        }

        $task->dependencies()->attach($dependencyId);

        return TaskResource::make($this->loadForResponse($task))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Task $task, Task $dependency): TaskResource
    {
        $this->authorize('update', $task);
        $this->authorize('view', $dependency);

        $task->dependencies()->detach($dependency->id);

        return new TaskResource($this->loadForResponse($task));
    }

    private function loadForResponse(Task $task): Task
    {
        return $task->load(['tags', 'assignee', 'dependencies'])->loadCount('subtasks');
    }
}
