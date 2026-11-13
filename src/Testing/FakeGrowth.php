<?php

namespace Shirahcan\GrowthClient\Testing;

use Shirahcan\GrowthClient\Exceptions\GrowthNotFound;
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
