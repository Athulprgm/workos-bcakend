<?php

namespace App\Http\Requests\Projects;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Project::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'workspace_id' => [
                'nullable',
                'integer',
                Rule::exists('workspaces', 'id')->where('user_id', $this->user()->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'status' => ['nullable', Rule::enum(ProjectStatus::class)],
            'priority' => ['nullable', 'string', Rule::in(['low', 'medium', 'high', 'urgent'])],
            'tech_stack' => ['nullable', 'array', 'max:30'],
            'tech_stack.*' => ['string', 'max:60'],
            'repository_url' => ['nullable', 'url', 'max:2048'],
            'start_date' => ['nullable', 'date'],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'milestones' => ['nullable', 'array'],
        ];
    }
}
