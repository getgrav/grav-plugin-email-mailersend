<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Tests\Support;

use Grav\Plugin\EmailMailersend\Http\Http;

/**
 * MailerSend's API, answering whatever a test told it to.
 *
 * Answers are handed out in the order they were queued, and running out of them
 * is a 599 with a sentence saying so rather than a silent empty answer — a test
 * that queued two answers for three calls should fail loudly on the third
 * rather than quietly on an assertion twenty lines later.
 */
final class FakeHttp implements Http
{
    /** @var list<array{method: string, url: string, body: array<string, mixed>|null, headers: array<string, string>}> */
    public array $calls = [];

    /** @var list<array{status: int, body: array<array-key, mixed>|null, error: string}> */
    private array $answers = [];

    /** @param array<array-key, mixed>|null $body */
    public function queue(int $status, ?array $body = null, string $error = ''): self
    {
        $this->answers[] = ['status' => $status, 'body' => $body, 'error' => $error];

        return $this;
    }

    /** Nothing answered at all, the way a refused connection looks. */
    public function queueFailure(string $error): self
    {
        return $this->queue(0, null, $error);
    }

    public function getJson(string $url, array $headers = []): array
    {
        $this->calls[] = ['method' => 'GET', 'url' => $url, 'body' => null, 'headers' => $headers];

        return $this->next();
    }

    public function sendJson(string $method, string $url, array $body, array $headers = []): array
    {
        $this->calls[] = ['method' => strtoupper($method), 'url' => $url, 'body' => $body, 'headers' => $headers];

        return $this->next();
    }

    /** @return array{method: string, url: string, body: array<string, mixed>|null, headers: array<string, string>} */
    public function call(int $index): array
    {
        return $this->calls[$index] ?? ['method' => '', 'url' => '', 'body' => null, 'headers' => []];
    }

    /** @return array{status: int, body: array<array-key, mixed>|null, error: string} */
    private function next(): array
    {
        return array_shift($this->answers) ?? [
            'status' => 599,
            'body' => null,
            'error' => 'the test queued no answer for this call',
        ];
    }
}
