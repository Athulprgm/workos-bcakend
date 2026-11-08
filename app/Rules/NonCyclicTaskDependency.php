<?php

namespace App\Rules;

use App\Models\Task;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Rejects a dependency that would block a task on itself, directly or through
 * a chain of other dependencies. Without this, two tasks can deadlock each
 * other and neither can ever be completed.
 */
class NonCyclicTaskDependency implements ValidationRule
{
    public function __construct(private readonly Task $task) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $dependency = Task::find($value);

        if (! $dependency instanceof Task) {
            $fail('The selected task does not exist.');

            return;
        }

        if ((int) $dependency->id === (int) $this->task->id) {
            $fail('A task cannot depend on itself.');

            return;
        }

        if ((int) $dependency->project_id !== (int) $this->task->project_id) {
            $fail('A dependency must belong to the same project.');

            return;
        }

        if ($this->dependsTransitivelyOn($dependency, (int) $this->task->id)) {
            $fail('This dependency would create a circular chain of blocked tasks.');
        }
    }

    /**
     * Walk the "depends on" graph outwards from $from looking for $targetId.
     */
    private function dependsTransitivelyOn(Task $from, int $targetId): bool
    {
        $visited = [];
        $queue = [(int) $from->id];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;

            $next = DB::table('task_dependencies')
                ->where('task_id', $current)
                ->pluck('depends_on_task_id');

            foreach ($next as $dependencyId) {
                if ((int) $dependencyId === $targetId) {
                    return true;
                }

                $queue[] = (int) $dependencyId;
            }
        }

        return false;
    }
}
