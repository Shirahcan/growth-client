<?php

namespace Shirahcan\GrowthClient\Laravel;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * The default actor: the signed-in user's id (the audit trail's key), and the NAME a person reads: the user's display
 * name, else their email. The name is not only an audit line: the service shows it as "changes from ..." in the
 * editor and starts a new post's byline with it, so an email there read as a public byline (Portify, 2026-11-17).
 *
 * ⚠ NO USER = NO WRITE. The proxy is mounted inside the product's admin auth group, so a request
 * without a user is a mounting mistake; it throws rather than recording "unknown".
 */
final class SessionGrowthActor implements GrowthActorResolver
{
    public function resolve(Request $request): array
    {
        $user = $request->user();
        if ($user === null) {
            throw new RuntimeException('growth-client: a proxied write needs a signed-in user. Mount GrowthRoutes inside the product\'s admin auth group.');
        }

        $display = trim((string) ($user->name ?? ''));
        $name = $display !== '' ? $display : ($user->email ?? 'user '.$user->getAuthIdentifier());

        return ['ref' => 'user:'.$user->getAuthIdentifier(), 'name' => (string) $name];
    }
}
