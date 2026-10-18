<?php

declare(strict_types=1);

use App\Queries\FathomTrafficQuery;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config(['services.fathom.token' => 'token', 'services.fathom.site_id' => 'SITE']);
});

it('is only available when a Fathom token and site are configured', function (?string $token, ?string $siteId, bool $isConfigured): void {
    config(['services.fathom.token' => $token, 'services.fathom.site_id' => $siteId]);

    expect(resolve(FathomTrafficQuery::class)->isConfigured())->toBe($isConfigured);
})->with([
    [null, 'SITE', false],
    ['', 'SITE', false],
    ['token', null, false],
    ['token', 'SITE', true],
]);

it('reads current visitors and the last day and week of traffic, and caches them for two minutes', function (): void {
    Http::fake([
        'api.usefathom.com/v1/current_visitors*' => Http::response(['total' => 7]),
        'api.usefathom.com/v1/aggregations*' => Http::sequence()
            ->push([['uniques' => '100', 'pageviews' => '250']])
            ->push([['uniques' => '600', 'pageviews' => '1500']])
            ->whenEmpty(Http::response([])),
    ]);

    $query = resolve(FathomTrafficQuery::class);
    $expected = ['current' => 7, 'day' => ['visitors' => 100, 'pageviews' => 250], 'week' => ['visitors' => 600, 'pageviews' => 1500]];

    expect($query->get())->toBe($expected)
        ->and($query->get())->toBe($expected);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer token')
        && ($request->data()['site_id'] ?? $request->data()['entity_id'] ?? null) === 'SITE');

    $this->travel(121)->seconds();
    $query->get();

    Http::assertSentCount(6);
});

it('counts no traffic as zero', function (): void {
    Http::fake([
        'api.usefathom.com/v1/current_visitors*' => Http::response(['total' => 0]),
        'api.usefathom.com/v1/aggregations*' => Http::response([]),
    ]);

    expect(resolve(FathomTrafficQuery::class)->get())->toBe([
        'current' => 0,
        'day' => ['visitors' => 0, 'pageviews' => 0],
        'week' => ['visitors' => 0, 'pageviews' => 0],
    ]);
});

it('is null when Fathom cannot be reached or answers with something unexpected', function (mixed $response): void {
    Http::fake(['api.usefathom.com/*' => $response]);

    expect(resolve(FathomTrafficQuery::class)->get())->toBeNull();
})->with([
    'an error' => fn () => Http::response(['error' => 'Unauthenticated'], 401),
    'rate limited' => fn () => Http::response([], 429),
    'no total' => fn () => Http::response(['message' => 'nope']),
    'a timeout' => fn () => Http::failedConnection(),
]);
