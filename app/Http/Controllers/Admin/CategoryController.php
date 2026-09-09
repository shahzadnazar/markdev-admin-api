<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(Request $request): View
    {
        $categories = Category::query()
            ->withCount('courses')
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.trim($request->string('search')).'%'))
            ->orderBy('name')
            ->paginate(12)
            ->appends(\Illuminate\Support\Arr::except($request->query(), ['partial', 'page']));

        // Live search re-renders only the results, so typing never reloads the
        // page and the cursor stays in the search box. The Filter button still
        // submits the form normally and lands here without `partial`.
        if ($request->boolean('partial')) {
            return view('admin.categories._results', ['categories' => $categories]);
        }

        return view('admin.categories.index', ['categories' => $categories]);
    }

    public function create(): View
    {
        return view('admin.categories.form', ['category' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        Category::create($data);

        return redirect()->route('admin.categories.index')->with('success', "Category \"{$data['name']}\" created.");
    }

    public function edit(Category $category): View
    {
        return view('admin.categories.form', ['category' => $category]);
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $category->update($this->validated($request, $category));

        return redirect()->route('admin.categories.index')->with('success', "Category \"{$category->name}\" updated.");
    }

    public function destroy(Category $category): RedirectResponse
    {
        $name = $category->name;
        $category->delete();

        return redirect()->route('admin.categories.index')->with('success', "Category \"{$name}\" deleted.");
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140', Rule::unique('categories', 'slug')->ignore($category?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $data['slug'] = $data['slug'] ?: Str::slug($data['name']);

        return $data;
    }
}
