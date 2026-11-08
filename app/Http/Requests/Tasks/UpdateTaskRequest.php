<?php

namespace App\Http\Requests\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Rules\ValidTaskParent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('task'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $task = $this->route('task');

        return [
            // Moving a task between projects is not supported: dependencies and
            // tags are project scoped, so a move could silently orphan them.
            'project_id' => ['prohibited'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'priority' => ['sometimes', Rule::enum(TaskPriority::class)],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'estimated_duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'actual_time_spent_minutes' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'completion_notes' => ['sometimes', 'nullable', 'string'],
            'recurring' => ['sometimes', 'nullable', 'string', Rule::in(['none', 'daily', 'weekly', 'monthly'])],
            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                new ValidTaskParent((int) $task->project_id, $task),
            ],
            'assignee_id' => ['sometimes', 'nullable', 'integer', Rule::in([$this->user()->id])],
            'tags' => ['sometimes', 'nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'project_id.prohibited' => 'A task cannot be moved to another project.',
            'assignee_id.in' => 'Tasks can only be assigned to the project owner until team support exists.',
        ];
    }
}
