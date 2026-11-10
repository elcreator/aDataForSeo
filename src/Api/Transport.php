<?php

namespace Elcreator\aDataForSeo\Api;

/**
 * The HTTP seam: the real network, the offline demo, or a test double.
 */
interface Transport
{
    /**
     * @param array<string, string> $headers
     * @return array{0: int, 1: string} HTTP status and body
     * @throws ApiException on a network failure (retryable)
     */
    public function send(string $method, string $url, ?string $body, array $headers, int $timeout, int $connectTimeout): array;
}
