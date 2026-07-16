<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class HelpController extends Controller
{
    /** Single help-center workspace: categories, articles, faqs. */
    public function index(Request $request): View
    {
        return view('admin.help.index', [
            'tab' => in_array($request->query('tab'), ['articles', 'categories', 'faqs'], true) ? $request->query('tab') : 'articles',
            'categories' => HelpCategory::withCount('articles')->orderBy('position')->get(),
            'articles' => HelpArticle::with('category')
                ->when($request->filled('search'), fn ($query) => $query->where('title', 'like', '%'.trim($request->string('search')).'%'))
                ->latest()
                ->paginate(10, ['*'], 'articles_page')
                ->withQueryString(),
            'faqs' => Faq::orderBy('position')->get(),
        ]);
    }

    /* ------------------------------ Categories ----------------------------- */

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $this->validatedCategory($request);
        HelpCategory::create($data);

        return redirect()->route('admin.help.index', ['tab' => 'categories'])->with('success', 'Help category created.');
    }

    public function updateCategory(Request $request, HelpCategory $category): RedirectResponse
    {
        $category->update($this->validatedCategory($request, $category));

        return redirect()->route('admin.help.index', ['tab' => 'categories'])->with('success', 'Help category updated.');
    }

    public function destroyCategory(HelpCategory $category): RedirectResponse
    {
        if ($category->articles()->exists()) {
            return redirect()->route('admin.help.index', ['tab' => 'categories'])
                ->with('error', 'Move or delete its articles before removing this category.');
        }

        $category->delete();

        return redirect()->route('admin.help.index', ['tab' => 'categories'])->with('success', 'Help category deleted.');
    }

    /* ------------------------------- Articles ------------------------------ */

    public function createArticle(): View
    {
        return view('admin.help.article-form', [
            'article' => null,
            'categories' => HelpCategory::orderBy('position')->get(['id', 'name']),
        ]);
    }

    public function storeArticle(Request $request): RedirectResponse
    {
        HelpArticle::create($this->validatedArticle($request));

        return redirect()->route('admin.help.index')->with('success', 'Article created.');
    }

    public function editArticle(HelpArticle $article): View
    {
        return view('admin.help.article-form', [
            'article' => $article,
            'categories' => HelpCategory::orderBy('position')->get(['id', 'name']),
        ]);
    }

    public function updateArticle(Request $request, HelpArticle $article): RedirectResponse
    {
        $article->update($this->validatedArticle($request, $article));

        return redirect()->route('admin.help.index')->with('success', 'Article updated.');
    }

    public function destroyArticle(HelpArticle $article): RedirectResponse
    {
        $article->delete();

        return redirect()->route('admin.help.index')->with('success', 'Article deleted.');
    }

    /* --------------------------------- FAQs -------------------------------- */

    public function storeFaq(Request $request): RedirectResponse
    {
        Faq::create($this->validatedFaq($request));

        return redirect()->route('admin.help.index', ['tab' => 'faqs'])->with('success', 'FAQ added.');
    }

    public function updateFaq(Request $request, Faq $faq): RedirectResponse
    {
        $faq->update($this->validatedFaq($request));

        return redirect()->route('admin.help.index', ['tab' => 'faqs'])->with('success', 'FAQ updated.');
    }

    public function destroyFaq(Faq $faq): RedirectResponse
    {
        $faq->delete();

        return redirect()->route('admin.help.index', ['tab' => 'faqs'])->with('success', 'FAQ deleted.');
    }

    /* -------------------------------- Support ------------------------------ */

    /** @return array<string, mixed> */
    protected function validatedCategory(Request $request, ?HelpCategory $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140', Rule::unique('help_categories', 'slug')->ignore($category?->id)],
            'position' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        $data['slug'] = $data['slug'] ?: Str::slug($data['name']);
        $data['position'] = $data['position'] ?? 0;

        return $data;
    }

    /** @return array<string, mixed> */
    protected function validatedArticle(Request $request, ?HelpArticle $article = null): array
    {
        $data = $request->validate([
            'help_category_id' => ['required', Rule::exists('help_categories', 'id')],
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', Rule::unique('help_articles', 'slug')->ignore($article?->id)],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $data['slug'] = $data['slug'] ?: Str::slug($data['title']);
        $data['is_published'] = $request->boolean('is_published');

        return $data;
    }

    /** @return array<string, mixed> */
    protected function validatedFaq(Request $request): array
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'answer' => ['required', 'string', 'max:5000'],
            'position' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $data['position'] = $data['position'] ?? 0;
        $data['is_published'] = $request->boolean('is_published');

        return $data;
    }
}
