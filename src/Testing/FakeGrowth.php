<?php

namespace Shirahcan\GrowthClient\Testing;

use Shirahcan\GrowthClient\Exceptions\GrowthNotFound;
use Shirahcan\GrowthClient\Exceptions\GrowthRequestRejected;
use Shirahcan\GrowthClient\Exceptions\GrowthServiceException;
use Shirahcan\GrowthClient\GrowthClient;

/**
 * In-memory stand-in for product tests: `app()->instance(GrowthClient::class, $fake)`.
 *
 * It keeps the CONTRACT the real service enforces, so a product test that passes here is not passing
 * on a promise production would refuse:
 * - the product is the fake's own (its "key"), never something the caller names;
 * - only an estate fake may {@see asProduct()}; a product fake gets the service's 403 `forbidden`;
 * - an unknown product gets the service's 404 `unknown_product`.
 *
 * Every call is recorded; read them with {@see calls()} (one log shared by every clone).
 */
class FakeGrowth implements GrowthClient
{
    private ?string $acting = null;

    /** @var \ArrayObject<int, array{method: string, product: string, acting: ?string, args: array<int, mixed>}> */
    private \ArrayObject $log;

    /**
     * @param  list<string>  $products  the service's product list (config growth-service.products)
     */
    public function __construct(
        private string $product = 'portify',
        private bool $estate = false,
        private array $products = ['portify', 'mploynow'],
    ) {
        $this->log = new \ArrayObject;
        $this->data = new \ArrayObject;
    }

    public static function forProduct(string $product = 'portify'): self
    {
        return new self($product);
    }

    public static function estate(string $ownProduct = 'portify'): self
    {
        return new self($ownProduct, estate: true);
    }

    public function whoami(): array
    {
        $this->record(__FUNCTION__);

        return ['product' => $this->product, 'acting_product' => $this->acting];
    }

    /** @var \ArrayObject<int, array<string, mixed>> pictures added through uploadMedia, newest last */
    public \ArrayObject $media;

    /** The next uploadMedia answers with this refusal (the store's or the library's sentence), once. */
    public ?string $refuseNextUpload = null;

    public function uploadLandingFile(string $pageUuid, string $path, string $name, string $origin, array $actor): array
    {
        $this->record(__FUNCTION__, [$pageUuid, $path, $name, $origin, $actor]);

        if ($this->refuseNextUpload !== null) {
            $message = $this->refuseNextUpload;
            $this->refuseNextUpload = null;
            throw new GrowthRequestRejected($message, 'invalid', 422);
        }

        $id = sprintf('%08x-0000-4000-8000-%012x', 1, crc32($pageUuid.$name));

        return ['page' => ['uuid' => $pageUuid, 'deliverable_kind' => 'file', 'has_file' => true, 'file_name' => $name, 'file_url' => rtrim($origin, '/').'/files/'.$id.'.pdf']];
    }

    public function uploadMedia(string $path, string $name, string $origin, array $actor, ?string $alt = null, ?string $sourceUrl = null): array
    {
        $this->record(__FUNCTION__, [$path, $name, $origin, $actor, $alt, $sourceUrl]);
        $this->media ??= new \ArrayObject;

        if ($this->refuseNextUpload !== null) {
            $message = $this->refuseNextUpload;
            $this->refuseNextUpload = null;
            throw new GrowthRequestRejected($message, 'invalid', 422);
        }

        foreach ($this->media as $row) {
            if ($sourceUrl !== null && ($row['source_url'] ?? null) === $sourceUrl) {
                return ['media' => $row, 'quota' => $this->mediaQuota()];
            }
        }

        $id = sprintf('%08x-0000-4000-8000-%012x', count($this->media) + 1, crc32($name));
        $row = [
            'id' => $id,
            'url' => rtrim($origin, '/').'/media/'.$id.'.jpg',
            'mime' => 'image/jpeg',
            'bytes' => is_file($path) ? (int) filesize($path) : 0,
            'width' => null,
            'height' => null,
            'alt' => $alt,
            'source_url' => $sourceUrl,
            'created_by' => $actor['ref'] ?? null,
        ];
        $this->media[] = $row;

        return ['media' => $row, 'quota' => $this->mediaQuota()];
    }

    /** @return array{bytes_used: int, bytes_limit: int, files_used: int, files_limit: int} */
    private function mediaQuota(): array
    {
        $rows = iterator_to_array($this->media ?? new \ArrayObject);

        return [
            'bytes_used' => array_sum(array_column($rows, 'bytes')),
            'bytes_limit' => 1073741824,
            'files_used' => count($rows),
            'files_limit' => 500,
        ];
    }

    public function asProduct(string $product): static
    {
        if ($product === $this->product && $this->acting === null) {
            return $this; // naming your own product is a no-op, as on the service
        }
        if (! $this->estate) {
            throw new GrowthServiceException('Only the estate admin key may read or change another product\'s Growth data.', 'forbidden', 403);
        }
        if (! in_array($product, $this->products, true)) {
            throw new GrowthNotFound("There is no product [{$product}].", 'unknown_product', 404);
        }

        $clone = clone $this;
        $clone->acting = $this->acting ?? $this->product;
        $clone->product = $product;

        return $clone;
    }

    /** @var \ArrayObject<string, array<string, mixed>> per product: ideas, signals, sources */
    private \ArrayObject $data;

    /**
     * Seed a product's Plan data the way the service would answer it.
     *
     * @param  list<array<string, mixed>>  $ideas
     * @param  list<array<string, mixed>>  $signals
     * @param  list<array<string, mixed>>  $sources
     */
    /** What `results()`, `resultsForPosts()` and `recordViews()` answer for a product (G6a2). */
    public function seedResults(string $product, ?array $results = null, array $posts = [], ?int $viewsAdded = null): static
    {
        $this->data[$product] = array_filter(['results' => $results, 'results_posts' => $posts, 'views_added' => $viewsAdded], fn ($v) => $v !== null) + ($this->data[$product] ?? []);

        return $this;
    }

    public function seedPlan(string $product, array $ideas = [], array $signals = [], array $sources = []): static
    {
        $this->data[$product] = ['ideas' => $ideas, 'signals' => $signals, 'sources' => $sources];

        return $this;
    }

    public function planIdeas(?string $month = null): array
    {
        $this->record(__FUNCTION__, [$month]);
        $ideas = $this->dataFor('ideas');
        $month = $month !== null && preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : date('Y-m');

        return [
            'month' => $month,
            'plan_due' => date('Y-m-d'),
            'accepted' => count(array_filter($ideas, fn ($i) => in_array($i['status'] ?? 'idea', ['accepted', 'drafted', 'published'], true))),
            'ideas' => $ideas,
        ];
    }

    public function planRadar(): array
    {
        $this->record(__FUNCTION__);

        return ['signals' => $this->dataFor('signals')];
    }

    public function watchedSources(): array
    {
        $this->record(__FUNCTION__);

        return ['sources' => $this->dataFor('sources')];
    }

    /** @return list<array<string, mixed>> only this fake's product's rows, as the service scopes them */
    /** Records the batch; `data['views_added']` (default: all) says how many were new. */
    public function recordViews(array $events): array
    {
        $this->record(__FUNCTION__, [$events]);

        return ['received' => count($events), 'added' => (int) (($this->data[$this->product] ?? [])['views_added'] ?? count($events))];
    }

    public function results(string $period = '30d'): array
    {
        $this->record(__FUNCTION__, [$period]);

        return (array) (($this->data[$this->product] ?? [])['results'] ?? ['period' => $period, 'new_subscribers' => [], 'reader_sources' => [], 'reader_to_booking' => [], 'conversions_known' => true]);
    }

    public function resultsForPosts(array $slugs): array
    {
        $this->record(__FUNCTION__, [$slugs]);
        $known = (array) (($this->data[$this->product] ?? [])['results_posts'] ?? []);

        return array_intersect_key($known, array_flip($slugs));
    }

    public function putResultsSettings(array $aiHosts, array $actor): array
    {
        $this->record(__FUNCTION__, [$aiHosts, $actor]);

        return ['results' => ['ai_hosts' => array_values($aiHosts)]];
    }

    private function dataFor(string $kind): array
    {
        return array_values(($this->data[$this->product] ?? [])[$kind] ?? []);
    }

    /** @return list<array{method: string, product: string, acting: ?string, args: array<int, mixed>}> */
    public function calls(?string $method = null): array
    {
        $all = $this->log->getArrayCopy();

        return array_values($method === null ? $all : array_filter($all, fn (array $c) => $c['method'] === $method));
    }

    /** @param  array<int, mixed>  $args */
    protected function record(string $method, array $args = []): void
    {
        $this->log->append(['method' => $method, 'product' => $this->product, 'acting' => $this->acting, 'args' => $args]);
    }
}
