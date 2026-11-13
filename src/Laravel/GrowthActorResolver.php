<?php

namespace Shirahcan\GrowthClient\Laravel;

use Illuminate\Http\Request;

/**
 * Who is acting, taken from the PRODUCT's own session (F1 rule 7). The proxy overwrites the request's
 * `actor` with this on every write row, so a browser can never claim to be somebody else.
 *
 * A product binds its own in `config('growth-client.actor')` when its user model names people
 * differently; {@see SessionGrowthActor} is the default.
 */
interface GrowthActorResolver
{
    /**
     * @return array{ref: string, name: string} `ref` is stable (e.g. "user:42"); `name` is what an
     *                                          audit line shows. Both are capped by the proxy.
     */
    public function resolve(Request $request): array;
}
