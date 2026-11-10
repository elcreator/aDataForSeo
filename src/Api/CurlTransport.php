<?php

namespace Elcreator\aDataForSeo\Api;

/**
 * The network, through ext-curl (gzip accepted; DataForSEO answers can be large).
 */
final class CurlTransport implements Transport
{
    public function send(string $method, string $url, ?string $body, array $headers, int $timeout, int $connectTimeout): array
    {
        $handle = curl_init($url);
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_ENCODING => '',
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($response === false) {
            throw ApiException::transport($error !== '' ? $error : 'no response');
        }

        return [$status, (string) $response];
    }
}
