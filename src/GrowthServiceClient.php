<?php

namespace Shirahcan\GrowthClient;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Shirahcan\GrowthClient\Exceptions\GrowthNotFound;
use Shirahcan\GrowthClient\Exceptions\GrowthRequestRejected;
use Shirahcan\GrowthClient\Exceptions\GrowthServiceException;
use Shirahcan\GrowthClient\Exceptions\GrowthServiceUnavailable;

/**
 * Talks to growth-service over loopback with this product's Bearer key.
 *
 * source: calendar-client `CalendarServiceClient` (one `send`, one error translation, typed
 * exceptions), adapted to growth-service's two envelopes: the house shape
 * `{success, message, data, errors?}` and the F1 refusal shape `{error, message}`.
 */
class GrowthServiceClient implements GrowthClient
{
    private ?string $asProduct = null;

    public function __construct(
        private string $baseUrl,
        private string $trustKey,
        private int $timeout = 10,
        private ?Guzzle $http = null,
        private string $estateKey = '',
    ) {
        $this->http ??= new Guzzle(['base_uri' => rtrim($this->baseUrl, '/').'/', 'timeout' => $this->timeout]);
    }

    /**
     * The proxy's door (G2b): one whitelisted call, answered with the service's own status and body so
     * a 422 lands beside its field. Only transport failures throw. `$estate` sends the estate key
     * (needed for `?as_product=`); a product that holds none gets {@see Exceptions\EstateKeyNotConfigured}.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @return array{status: int, body: array<string, mixed>}
     */
    public function forward(string $method, string $path, array $query = [], array $body = [], bool $estate = false): array
    {
        if ($estate && $this->estateKey === '') {
            throw new Exceptions\EstateKeyNotConfigured();
        }

        $options = [
            'headers' => ['Authorization' => 'Bearer '.($estate ? $this->estateKey : $this->trustKey), 'Accept' => 'application/json'],
            'http_errors' => false,
        ];
        if ($query !== []) {
            $options['query'] = $query;
        }
        if ($method !== 'GET' && $body !== []) {
            $options['json'] = $body;
        }

        try {
            $response = $this->http->request($method, ltrim($path, '/'), $options);
        } catch (ConnectException $e) {
            throw new GrowthServiceUnavailable('growth-service is unreachable: '.$e->getMessage(), 'unreachable', 0);
        } catch (GuzzleException $e) {
            throw new GrowthServiceUnavailable('growth-service request failed: '.$e->getMessage(), 'transport', 0);
        }

        $json = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($json) ? $json : []];
    }

    public function whoami(): array
    {
        $json = $this->send('GET', 'api/v1/whoami');

        return [
            'product' => (string) ($json['product'] ?? ''),
            'acting_product' => isset($json['acting_product']) ? (string) $json['acting_product'] : null,
        ];
    }

    public function asProduct(string $product): static
    {
        $clone = clone $this;
        $clone->asProduct = $product;

        return $clone;
    }

    /**
     * One request, one translation. Returns the house envelope's `data`, or the whole body for the few
     * F1 routes that answer bare JSON (whoami).
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function send(string $method, string $path, array $body = []): array
    {
        $query = $this->asProduct !== null ? ['as_product' => $this->asProduct] : [];
        $options = ['headers' => ['Authorization' => 'Bearer '.$this->trustKey, 'Accept' => 'application/json'], 'http_errors' => false];

        if ($method === 'GET') {
            $query += $body;
        } elseif ($body !== []) {
            $options['json'] = $body;
        }
        if ($query !== []) {
            $options['query'] = $query;
        }

        try {
            $response = $this->http->request($method, $path, $options);
        } catch (ConnectException $e) {
            throw new GrowthServiceUnavailable('growth-service is unreachable: '.$e->getMessage(), 'unreachable', 0);
        } catch (GuzzleException $e) {
            throw new GrowthServiceUnavailable('growth-service request failed: '.$e->getMessage(), 'transport', 0);
        }

        $json = json_decode((string) $response->getBody(), true);
        $json = is_array($json) ? $json : [];
        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            return array_key_exists('success', $json) && array_key_exists('data', $json)
                ? (array) ($json['data'] ?? [])
                : $json;
        }

        throw self::errorFor($response, $json);
    }

    /**
     * ONE translation from the service's answers to the typed exceptions.
     *
     * @param  array<string, mixed>  $json
     */
    public static function errorFor(ResponseInterface $response, array $json): GrowthServiceException
    {
        $status = $response->getStatusCode();
        $code = is_string($json['error'] ?? null) ? $json['error'] : null;
        $message = (string) ($json['message'] ?? "growth-service answered {$status}");
        $errors = is_array($json['errors'] ?? null) ? $json['errors'] : [];

        return match (true) {
            $status === 404 => new GrowthNotFound($message, $code ?? 'not_found', $status),
            $status >= 500 || $status === 429 => new GrowthServiceUnavailable($message, $code ?? 'server_error', $status),
            in_array($status, [409, 422], true) => new GrowthRequestRejected($message, $code ?? ($status === 409 ? 'conflict' : 'invalid'), $status, $errors),
            // 401: this product's key is missing, wrong or revoked. 403: a product key named another
            // product. Both are deployment or programming faults: reported, never shown as-is.
            default => new GrowthServiceException($message, $code ?? 'unexpected', $status, $errors),
        };
    }
}
