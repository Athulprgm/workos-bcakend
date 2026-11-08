<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TagResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TagController extends Controller
{
    /**
     * The caller's tags, used to populate tag suggestions.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return TagResource::collection(
            $request->user()->tags()->orderBy('name')->get()
        );
    }
}
