<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\FaqResource;
use App\Http\Resources\HelpArticleResource;
use App\Http\Resources\HelpCategoryResource;
use App\Models\Faq;
use App\Models\HelpArticle;
use App\Models\HelpCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class HelpController extends ApiController
{
    public function categories(): AnonymousResourceCollection
    {
        $categories = HelpCategory::query()
            ->withCount(['articles as articles_count' => fn (Builder $q) => $q->where('is_published', true)])
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return HelpCategoryResource::collection($categories);
    }

    public function articles(Request $request): AnonymousResourceCollection
    {
        $query = HelpArticle::published()->with('category');

        if ($category = $request->query('category')) {
            $query->whereHas('category', fn (Builder $q) => $q->where('slug', $category));
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn (Builder $q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('excerpt', 'like', "%{$search}%")
                ->orWhere('body', 'like', "%{$search}%"));
        }

        $articles = $query->orderBy('title')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return HelpArticleResource::collection($articles);
    }

    public function article(string $slug): HelpArticleResource
    {
        $article = HelpArticle::published()->where('slug', $slug)->with('category')->firstOrFail();

        $article->setAttribute('with_body', true);

        return new HelpArticleResource($article);
    }

    public function faqs(): AnonymousResourceCollection
    {
        $faqs = Faq::where('is_published', true)->orderBy('position')->orderBy('id')->get();

        return FaqResource::collection($faqs);
    }
}
