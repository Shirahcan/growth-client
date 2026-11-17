<?php

namespace Shirahcan\GrowthClient\Knowledge;

use Shirahcan\GrowthClient\Facts\FactsUnavailable;

/**
 * Where a published post becomes something the product's assistant can answer from (blog-on-growth-service S4a).
 *
 * WHO DECIDES WHAT: the SERVICE decides a post belongs in knowledge. It sends `upsert` only for a published,
 * non-deleted post marked "add to knowledge", and `remove` the moment any of those stops being true. The PRODUCT
 * decides whether to SERVE it (`served`) or keep it unserved (`held`, e.g. while its public pages are off, so the
 * link in an answer would not open), and owns the row and its rules. Both calls are IDEMPOTENT here: the service
 * retries with backoff, so a duplicate must never write twice. The service never blocks a publish on this.
 *
 * The product binds one implementation (`growth-client.knowledge_sink`); the kit serves it at
 * `POST {webhookRoute}/knowledge/upsert` and `POST {webhookRoute}/knowledge/remove`, signed like `/facts`.
 *
 * The post, from the PUBLISHED snapshot (never the draft):
 *   - id (stable uuid), slug, title, answer, meta_description
 *   - audience: applicants | employers | consultants
 *   - category: {slug, name} | null
 *   - reader_questions: [{q, a}]
 *   - facts: [{label, value, review_by: Y-m-d|null, source_key: string|null}]
 *   - published_at: ISO 8601
 * There is NO url: the product owns its public route and builds the link from `slug`.
 */
interface KnowledgeSinkAdapter
{
    /**
     * @param  array<string, mixed>  $post
     * @return array{state: 'served'|'held', reason: string|null}
     *
     * @throws FactsUnavailable when the product cannot take it right now (a plain sentence; the service retries)
     */
    public function upsert(array $post): array;

    /**
     * @return array{state: 'removed'|'none'}
     *
     * @throws FactsUnavailable when the product cannot take it right now
     */
    public function remove(string $postId, string $reason): array;
}
