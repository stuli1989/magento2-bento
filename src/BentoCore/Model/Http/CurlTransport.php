<?php

declare(strict_types=1);

namespace ArtLounge\BentoCore\Model\Http;

class CurlTransport implements TransportInterface
{
    public function request(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $error = curl_errno($ch) ? curl_error($ch) : null;
        $status = $error === null ? (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) : 0;
        curl_close($ch);

        return [
            'status' => $status,
            'body' => is_string($responseBody) ? $responseBody : '',
            'error' => $error,
        ];
    }
}
