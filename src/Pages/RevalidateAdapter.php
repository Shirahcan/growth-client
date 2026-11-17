<?php

namespace Shirahcan\GrowthClient\Pages;

use Shirahcan\GrowthClient\Facts\FactsUnavailable;

/**
 * What readers see changed: the product drops its cached public pages for it (blog-on-growth-service S4a).
 *
 * The change is NEUTRAL, never the product's own cache keys, and the product maps it to them (Portify: tags `blog`,
 * `blog:{slug}`, and `blog:{previous_slug}` when the slug moved):
 *   - event: published | unpublished | updated | deleted
 *   - slug, previous_slug (string|null, on a slug change), category_slugs: [string]
 *
 * HONEST, NOT FATAL: a bust that did not happen answers `revalidated: false` with the reason; the page's own
 * revalidate window is the backstop. IDEMPOTENT: the service retries with backoff and never blocks a publish on it.
 *
 * The product binds one implementation (`growth-client.revalidate`); the kit serves it at
 * `POST {webhookRoute}/revalidate`, signed like `/facts`.
 */
interface RevalidateAdapter
{
    /** The events a change can carry. */
    public const EVENTS = ['published', 'unpublished', 'updated', 'deleted'];

    /**
     * @param  array{event: string, slug: string, previous_slug: string|null, category_slugs: list<string>}  $change
     * @return array{revalidated: bool, detail: string|null}
     *
     * @throws FactsUnavailable when the product cannot take it right now
     */
    public function revalidate(array $change): array;
}
