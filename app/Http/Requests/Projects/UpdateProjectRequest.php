<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('project'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'workspace_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('workspaces', 'id')->where('user_id', $this->user()->id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'status' => ['sometimes', Rule::enum(ProjectStatus::class)],
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'tech_stack' => ['sometimes', 'nullable', 'array', 'max:30'],
            'tech_stack.*' => ['string', 'max:60'],
            'repository_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'milestones' => ['sometimes', 'nullable', 'array'],
            'archived_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
