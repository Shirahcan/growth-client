<?php

namespace Shirahcan\GrowthClient\Posts;

use Shirahcan\GrowthClient\Facts\FactsUnavailable;

/**
 * Extra ADVISORY readiness checks a product adds to a post (blog-on-growth-service S4; agreed with portify-e7 and
 * portify-ab). Portify's: the CICC s.44 licensee byline check.
 *
 * ADVISORY, NEVER A LOCK: the owner decided a post locks on its structure only, so the service forces
 * `required = false` on every product check; `required` only sorts an item as "should fix". FAIL-OPEN: an absent
 * port, 503, a timeout (the service waits 3 seconds) or a malformed answer means "no product checks", never a blocked
 * readiness.
 *
 * The product binds one implementation (`growth-client.post_checks`); the kit serves it at
 * `POST {webhookRoute}/posts/checks` with body `{post}`, signed like `/facts`.
 *
 * The post: id, title, author_name, cta_kind, cta_ref, audience, category_slug.
 */
interface PostChecksAdapter
{
    /**
     * @param  array<string, mixed>  $post
     * @return list<array{key: string, label: string, hint: string|null, ok: bool, required: bool, weight: int}>
     *
     * @throws FactsUnavailable when the product cannot answer right now (the service treats it as no checks)
     */
    public function checks(array $post): array;
}
