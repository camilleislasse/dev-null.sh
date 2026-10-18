<?php

declare(strict_types=1);

namespace App\Queries;

use App\Services\GitHub;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Fluent;

/**
 * @phpstan-type Contributor array{login: string, commits: int}
 */
final readonly class ContributorsQuery
{
    private const int PerPage = 100;

    private const int MaxPages = 10;

    private const int Ttl = 3600;

    private const int FailedTtl = 60;

    /**
     * GitHub reports them as ordinary accounts, so they are named rather than detected.
     */
    private const array Agents = ['ampagent', 'claude'];

    public function __construct(private GitHub $github)
    {
        //
    }

    /**
     * Everyone with a commit on production, most commits first, as GitHub counts them. A failed read is kept for a
     * minute, so an outage doesn't send every visitor to GitHub.
     *
     * @return list<Contributor>|null
     */
    public function get(): ?array
    {
        $repository = config()->string('services.github.repository');
        $key = "about:contributors:{$repository}";

        /** @var list<Contributor>|false|null $contributors */
        $contributors = Cache::get($key);

        if ($contributors === null) {
            $contributors = $this->fetch($repository) ?? false;

            Cache::put($key, $contributors, $contributors === false ? self::FailedTtl : self::Ttl);
        }

        return $contributors === false ? null : $contributors;
    }

    /**
     * @return list<Contributor>|null
     */
    private function fetch(string $repository): ?array
    {
        $people = [];

        for ($page = 1; $page <= self::MaxPages; $page++) {
            try {
                $accounts = $this->github->request()->get("repos/{$repository}/contributors", [
                    'per_page' => self::PerPage,
                    'page' => $page,
                ])->throw()->collect();
            } catch (HttpClientException) {
                return null;
            }

            if ($accounts->isNotEmpty() && ! array_is_list($accounts->all())) {
                return null;
            }

            $people = [...$people, ...$this->people($accounts)];

            if ($accounts->count() < self::PerPage) {
                break;
            }
        }

        return $people;
    }

    /**
     * @param  Collection<int|string, mixed>  $accounts
     * @return list<Contributor>
     */
    private function people(Collection $accounts): array
    {
        return array_values($accounts->where('type', 'User')
            ->mapInto(Fluent::class)
            ->filter($this->isPerson(...))
            ->map(fn (Fluent $account): array => [
                'login' => $account->string('login')->value(),
                'commits' => $account->integer('contributions'),
            ])
            ->all());
    }

    /**
     * An account with a name and a count behind it, and not one of the agents.
     *
     * @param  Fluent<string, mixed>  $account
     */
    private function isPerson(Fluent $account): bool
    {
        $login = $account->string('login')->value();

        return $login !== ''
            && is_int($account->get('contributions'))
            && ! in_array(mb_strtolower($login), self::Agents, true);
    }
}
