<?php

namespace Shirahcan\GrowthClient\Laravel\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Shirahcan\GrowthClient\GrowthServiceClient;

/**
 * Opens and clicks on mail growth-service sent (G5d2), reached on the product's own domain: the pixel and the click
 * links in the email point here, and this hands them to the service with the product's key.
 *
 * ⚠ THE PIXEL ALWAYS ANSWERS AN IMAGE, whatever the service says (a mail client shows a broken image otherwise), and
 * never waits on a failure longer than the client's own timeout. ⚠ A CLICK REDIRECTS ONLY WHERE THE SERVICE SAYS, and
 * the service says so only for a destination it signed when it wrote the email: no open redirect through this door.
 */
final class GrowthTrackController
{
    /** A 1x1 transparent GIF. */
    private const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(Request $request, GrowthServiceClient $client, string $token): Response
    {
        try {
            $result = $client->forward('GET', 'api/v1/track/'.rawurlencode($token).'/open');
            if ($result['status'] >= 500) {
                report(new \RuntimeException("growth-service track open answered {$result['status']}"));
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return new Response((string) base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(Request $request, GrowthServiceClient $client, string $token): RedirectResponse|Response
    {
        try {
            $result = $client->forward('GET', 'api/v1/track/'.rawurlencode($token).'/click', [
                'u' => (string) $request->query('u', ''), 's' => (string) $request->query('s', ''),
            ]);
        } catch (\Throwable $e) {
            report($e);
            $result = ['status' => 503, 'body' => []];
        }
        $to = (string) ($result['body']['data']['redirect'] ?? '');
        if ($result['status'] === 200 && (str_starts_with($to, 'https://') || str_starts_with($to, 'http://'))) {
            return new RedirectResponse($to, 302);
        }

        return new Response('This link could not be opened. It may have been copied incompletely.', $result['status'] === 503 ? 503 : 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
