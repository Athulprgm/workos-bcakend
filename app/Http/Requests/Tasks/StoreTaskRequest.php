<?php

namespace App\Http\Requests\Tasks;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Rules\ValidTaskParent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'workspace_id' => [
                'nullable',
                'integer',
                Rule::exists('workspaces', 'id')->where('user_id', $this->user()->id),
            ],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'status' => ['nullable', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'due_at' => ['nullable', 'date'],
            'estimated_duration_minutes' => ['nullable', 'integer', 'min:0'],
            'actual_time_spent_minutes' => ['nullable', 'integer', 'min:0'],
            'completion_notes' => ['nullable', 'string'],
            'recurring' => ['nullable', 'string', Rule::in(['none', 'daily', 'weekly', 'monthly'])],
            'parent_id' => [
                'nullable',
                'integer',
                'exists:tasks,id',
                new ValidTaskParent((int) $this->input('project_id', 0)),
            ],
            'assignee_id' => ['nullable', 'integer', Rule::in([$this->user()->id])],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assignee_id.in' => 'Tasks can only be assigned to the project owner until team support exists.',
            'project_id.exists' => 'The selected project does not exist.',
        ];
    }
}
