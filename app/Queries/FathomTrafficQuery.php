<?php

declare(strict_types=1);

namespace App\Queries;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final readonly class FathomTrafficQuery
{
    public function isConfigured(): bool
    {
        return filled(config('services.fathom.token')) && filled(config('services.fathom.site_id'));
    }

    /**
     * Every Fathom API call counts against the account's monthly pageviews and hourly rate limit, so traffic is cached
     * for two minutes. A failed read is not cached, so the next refresh tries again.
     *
     * @return array{current: int, day: array{visitors: int, pageviews: int}, week: array{visitors: int, pageviews: int}}|null
     */
    public function get(): ?array
    {
        /** @var array{current: int, day: array{visitors: int, pageviews: int}, week: array{visitors: int, pageviews: int}}|null */
        return Cache::remember('operations:fathom-traffic', 120, function (): ?array {
            try {
                $current = $this->request()->get('current_visitors', ['site_id' => config('services.fathom.site_id')])->throw()->json('total');

                $totals = collect(['day' => now()->subDay(), 'week' => now()->subWeek()])
                    ->map(fn (CarbonImmutable $from): array => $this->totalsSince($from))
                    ->all();
            } catch (HttpClientException) {
                return null;
            }

            return is_int($current) ? ['current' => $current, ...$totals] : null;
        });
    }

    /**
     * Fathom answers with numbers as strings, and with no row at all when nothing was recorded in the range.
     *
     * @return array{visitors: int, pageviews: int}
     */
    private function totalsSince(CarbonImmutable $from): array
    {
        $totals = $this->request()
            ->get('aggregations', [
                'entity' => 'pageview',
                'entity_id' => config('services.fathom.site_id'),
                'aggregates' => 'uniques,pageviews',
                'date_from' => $from->utc()->format('Y-m-d H:i:s'),
                'date_to' => now()->utc()->format('Y-m-d H:i:s'),
                'timezone' => 'UTC',
            ])
            ->throw()
            ->fluent('0');

        return [
            'visitors' => $totals->integer('uniques'),
            'pageviews' => $totals->integer('pageviews'),
        ];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl('https://api.usefathom.com/v1')
            ->acceptJson()
            ->withToken(config()->string('services.fathom.token'))
            ->connectTimeout(3)
            ->timeout(5);
    }
}
