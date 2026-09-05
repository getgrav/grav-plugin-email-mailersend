<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Http;

/**
 * The handful of outbound requests this plugin makes, behind one seam.
 *
 * Creating a webhook in MailerSend's API, listing the ones that are already
 * there, and reading a sending domain's DNS records back. Four calls in the
 * whole plugin, and every one of them is the sort of thing a test has to be
 * able to answer for itself — a suite that reached the network would be a suite
 * that failed on a train.
 *
 * Deliberately tiny. No redirect policy to configure, no streaming, no header
 * manipulation beyond the two that every one of these calls needs, because none
 * of them needs any of it and each would be another thing to get wrong in the
 * one class that talks to the outside.
 */
interface Http
{
    /**
     * GET a URL and read a JSON answer.
     *
     * @param array<string, string> $headers
     * @return array{status: int, body: array<array-key, mixed>|null, error: string}
     */
    public function getJson(string $url, array $headers = []): array;

    /**
     * Send a JSON body and read a JSON answer.
     *
     * @param string                $method POST or PUT; nothing here deletes anything
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     * @return array{status: int, body: array<array-key, mixed>|null, error: string}
     */
    public function sendJson(string $method, string $url, array $body, array $headers = []): array;
}
