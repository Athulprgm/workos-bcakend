<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes (API Service Status)
|--------------------------------------------------------------------------
|
| The WorkOS backend operates as a headless API service. The user interface
| is independently hosted in the React frontend application.
|
*/

Route::get('/', function () {
    return response()->json([
        'app' => config('app.name', 'WorkOS API'),
        'environment' => app()->environment(),
        'status' => 'healthy',
        'api_version' => '1.0.0',
        'endpoints' => [
            'auth' => '/api/auth',
            'dashboard' => '/api/dashboard',
            'projects' => '/api/projects',
            'tasks' => '/api/tasks',
            'tags' => '/api/tags',
        ],
    ]);
})->name('api.status');
