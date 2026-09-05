<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Http;

/**
 * {@see Http} over cURL, with every switch that matters set explicitly.
 *
 * The settings are not defaults and they are not decoration:
 *
 * - **HTTPS only**, on the request and on any redirect it follows. Every one of
 *   these calls carries an API token in a header, and one plain-HTTP redirect
 *   would put that token on the wire in the clear.
 * - **Peer and host verification on.** Nobody's build turns it off by default,
 *   and it is worth stating anyway: this is the class where turning it off
 *   would be quiet and expensive.
 * - **Two redirects.** Enough for a provider that moved an endpoint, not enough
 *   to be walked around a network.
 * - **A response cap.** A webhook is a few hundred bytes and a domain list is a
 *   few thousand. Ten megabytes of anything is somebody pointing this at a file
 *   server, and the write callback stops reading rather than filling memory.
 * - **Short timeouts.** These calls run behind a button on a settings screen,
 *   and a merchant staring at a spinner for a minute will press it again.
 */
final class CurlHttp implements Http
{
    /** Seconds to connect. */
    public const CONNECT_TIMEOUT = 5;

    /** Seconds for the whole call. */
    public const TIMEOUT = 15;

    /** Bytes of response body kept before the transfer is abandoned. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    public function getJson(string $url, array $headers = []): array
    {
        return $this->run('GET', $url, null, $headers);
    }

    public function sendJson(string $method, string $url, array $body, array $headers = []): array
    {
        $json = json_encode($body, \JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return ['status' => 0, 'body' => null, 'error' => 'the request body could not be encoded'];
        }

        return $this->run($method, $url, $json, $headers + ['Content-Type' => 'application/json']);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: array<array-key, mixed>|null, error: string}
     */
    private function run(string $method, string $url, ?string $payload, array $headers): array
    {
        if (!\function_exists('curl_init')) {
            return ['status' => 0, 'body' => null, 'error' => 'this installation has no cURL'];
        }

        if (!str_starts_with(strtolower(trim($url)), 'https://')) {
            return ['status' => 0, 'body' => null, 'error' => 'only https addresses are called'];
        }

        $handle = curl_init($url);
        if ($handle === false) {
            return ['status' => 0, 'body' => null, 'error' => 'the request could not be started'];
        }

        $lines = ['Accept: application/json'];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $raw = '';

        curl_setopt_array($handle, [
            \CURLOPT_CUSTOMREQUEST => strtoupper($method),
            \CURLOPT_RETURNTRANSFER => false,
            \CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            \CURLOPT_TIMEOUT => self::TIMEOUT,
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 2,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
            \CURLOPT_PROTOCOLS => \CURLPROTO_HTTPS,
            \CURLOPT_REDIR_PROTOCOLS => \CURLPROTO_HTTPS,
            \CURLOPT_HTTPHEADER => $lines,
            \CURLOPT_WRITEFUNCTION => static function ($_, string $chunk) use (&$raw): int {
                $raw .= $chunk;

                // Handing cURL back fewer bytes than it gave us is how it is
                // told to stop, which is the point: a body over the cap is
                // abandoned rather than assembled and then thrown away.
                return \strlen($raw) > self::MAX_BYTES ? 0 : \strlen($chunk);
            },
        ]);

        if ($payload !== null) {
            curl_setopt($handle, \CURLOPT_POSTFIELDS, $payload);
        }

        $ok = curl_exec($handle);
        $status = (int)curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        $error = $ok === false ? (string)curl_error($handle) : '';
        curl_close($handle);

        return ['status' => $status, 'body' => self::decode($raw), 'error' => $error];
    }

    /** @return array<array-key, mixed>|null */
    private static function decode(string $raw): ?array
    {
        if (trim($raw) === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }
}
