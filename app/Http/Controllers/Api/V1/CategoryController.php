<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends ApiController
{
    public function index(): AnonymousResourceCollection
    {
        $categories = Category::query()
            ->withCount(['courses as courses_count' => fn ($query) => $query->published()])
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories);
    }
}
