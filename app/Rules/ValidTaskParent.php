<?php

namespace App\Rules;

use App\Models\Task;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Ensures a subtask's parent is in the same project and cannot create a
 * circular subtask hierarchy.
 */
class ValidTaskParent implements ValidationRule
{
    /**
     * @param  int  $projectId  the project the child task belongs to
     * @param  Task|null  $task  the task being updated, or null when creating
     */
    public function __construct(
        private readonly int $projectId,
        private readonly ?Task $task = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $parent = Task::find($value);

        if (! $parent instanceof Task) {
            $fail('The selected parent task does not exist.');

            return;
        }

        if ((int) $parent->project_id !== $this->projectId) {
            $fail('The parent task must belong to the same project.');

            return;
        }

        if (! $this->task instanceof Task) {
            return;
        }

        if ((int) $parent->id === (int) $this->task->id) {
            $fail('A task cannot be its own parent.');

            return;
        }

        if ($this->task->isAncestorOf($parent)) {
            $fail('The selected parent would create a circular subtask relationship.');
        }
    }
}
