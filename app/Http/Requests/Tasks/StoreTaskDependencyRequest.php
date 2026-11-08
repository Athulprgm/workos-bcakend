<?php

namespace App\Http\Requests\Tasks;

use App\Rules\NonCyclicTaskDependency;
use Illuminate\Foundation\Http\FormRequest;

class StoreTaskDependencyRequest extends FormRequest
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
        return [
            'depends_on_task_id' => [
                'required',
                'integer',
                'exists:tasks,id',
                new NonCyclicTaskDependency($this->route('task')),
            ],
        ];
    }
}
