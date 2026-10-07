<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\CMS\Models\Content;
use Modules\CMS\Services\ContentExtensionResolver;
use Modules\Core\Overrides\LocaleScope;
use Modules\Shop\Enums\ProductKind;
use Modules\Shop\Models\Product;

beforeEach(function (): void {
    setupShopEntities();
});

it('an extended product content is hidden from a plain Content query', function (): void {
    $product = Product::factory()->create();

    expect(Content::query()->count())->toBe(0)
        ->and(Content::query()->find($product->content_id))->toBeNull()
        ->and(Content::withExtended()->find($product->content_id))->not->toBeNull();
});

it('a product listing upcasts in a constant number of queries', function (): void {
    $resolver = app(ContentExtensionResolver::class);

    // The upcast consumes a lean page of extended contents: it only reads each row's key and
    // `extended_type`, then re-attaches that very content to its extender via setRelation(). The
    // editorial `presettable`/`translation` eager-loads Content carries in `$with` are not part of
    // the upcast, so the pipeline's own cost is two queries — one for the content page, one batched
    // query for all extenders — no matter how many rows the page holds (C5/C6, spec "two queries per
    // entity-scoped page").
    $fetchPage = static fn (): Illuminate\Support\Collection => Content::withExtended()
        ->withoutGlobalScope(LocaleScope::class)
        ->without('presettable', 'translation')
        ->get();

    $upcastQueryCount = static function () use ($resolver, $fetchPage): int {
        // Warm the request-scoped permission-existence memos (Content/Product `select`) so the count
        // reflects the steady-state cost, not first-touch cache fills that happen once per request.
        $resolver->resolve($fetchPage());

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        $resolver->resolve($fetchPage());
        $queries = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        return $queries;
    };

    Product::factory()->count(5)->create();
    $resolvedForFive = $resolver->resolve($fetchPage());
    $queriesForFive = $upcastQueryCount();

    // Grow the page: the count must not grow with it (no N+1).
    Product::factory()->count(7)->create();
    $resolvedForTwelve = $resolver->resolve($fetchPage());
    $queriesForTwelve = $upcastQueryCount();

    expect($resolvedForFive)->toHaveCount(5)
        ->and($resolvedForFive->every(static fn ($item): bool => $item instanceof Product))->toBeTrue()
        ->and($resolvedForTwelve)->toHaveCount(12)
        ->and($queriesForFive)->toBe(2)
        ->and($queriesForTwelve)->toBe(2);
});

it('a content cannot be extended by two products', function (): void {
    $first = Product::factory()->create();

    $second = new Product([
        'content_id' => $first->content_id,
        'company_id' => $first->company_id,
        'kind' => ProductKind::Physical->value,
    ]);
    $second->save();
})->throws(QueryException::class);

it('deleting a product deletes its content and vice versa', function (): void {
    // Extender -> content: soft-deleting the product cascades to its content (C9/E17).
    $product = Product::factory()->create();
    $contentId = $product->content_id;

    $product->delete();

    expect(Content::withExtended()->find($contentId))->toBeNull()
        ->and(Content::withExtended()->withTrashed()->find($contentId))->not->toBeNull();

    // Content -> extender: deleting the content directly cascades to the product (no bodiless orphan).
    $other = Product::factory()->create();

    $other->content->delete();

    expect(Product::query()->find($other->getKey()))->toBeNull()
        ->and(Product::withTrashed()->find($other->getKey()))->not->toBeNull();
});

it('product reads and writes merged content fields', function (): void {
    $product = Product::factory()->create();

    // Read-through: a content-owned attribute reads as if native on the product.
    expect($product->valid_from)->not->toBeNull()
        ->and($product->valid_from->toDateString())->toBe($product->content->valid_from->toDateString());

    // Write-through: setting a content-owned attribute on the product root persists it on the Content.
    $date = now()->addDays(10);
    $product->valid_to = $date;
    $product->save();

    $fresh = Product::query()->findOrFail($product->getKey());

    expect($fresh->valid_to)->not->toBeNull()
        ->and($fresh->valid_to->toDateString())->toBe($date->toDateString())
        ->and($fresh->content->valid_to->toDateString())->toBe($date->toDateString());
});
