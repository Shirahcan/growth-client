<?php

namespace Shirahcan\GrowthClient\Laravel;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * The default actor: the signed-in user's id, and their email (or name) for the audit line.
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

        $name = $user->email ?? $user->name ?? 'user '.$user->getAuthIdentifier();

        return ['ref' => 'user:'.$user->getAuthIdentifier(), 'name' => (string) $name];
    }
}
