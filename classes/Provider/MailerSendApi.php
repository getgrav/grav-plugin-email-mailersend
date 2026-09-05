<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Provider;

use Grav\Plugin\EmailMailersend\Http\Http;

/**
 * The four calls this plugin makes to MailerSend's API.
 *
 * Documentation: `developers.mailersend.com/api/v1/account/webhooks`,
 * `developers.mailersend.com/api/v1/email/domains` and
 * `developers.mailersend.com/general`, read 2026-09-05.
 *
 * Every method answers plain data and never throws. That is not tidiness: two
 * of these run behind a button on a settings screen and the other two run while
 * a Deliverability panel is being drawn, and neither place is one where an
 * exception out of somebody else's API is an acceptable outcome.
 *
 * ## Authentication and errors
 *
 * `Authorization: Bearer <token>`. MailerSend answers 401 for a token it does
 * not recognise, 403 for one whose permissions do not cover the endpoint, and
 * 422 with Laravel's `{message, errors: {field: [sentence]}}` for a request it
 * would not accept. {@see reason()} turns all three into one sentence, because
 * the merchant reading it wants to know what to change rather than what form
 * the answer arrived in.
 *
 * ## Why the domain id is a whole problem
 *
 * `POST /v1/webhooks` requires `domain_id`, and a domain id is a sixteen
 * character hash that appears nowhere a merchant would think to look. So the id
 * is resolved rather than asked for: from the plugin's own config when somebody
 * has pasted one, otherwise by name against `GET /v1/domains`, otherwise — for
 * the very common account with exactly one sending domain — by taking the only
 * one there is. Asking a merchant to find a hash is how a setup button turns
 * into a support ticket.
 */
final class MailerSendApi
{
    /** The one base. MailerSend has no regional endpoints. */
    public const BASE = 'https://api.mailersend.com/v1';

    /** How many domains one page of `GET /v1/domains` asks for. Their maximum. */
    public const DOMAIN_PAGE = 100;

    public function __construct(private readonly Http $http)
    {
    }

    /**
     * The domains on this account, as `id => name`.
     *
     * Needs the token's Domains permission set to at least read only. An empty
     * list is a real answer — a token without that permission, or an account
     * with no verified domain — and every caller treats it as "could not say"
     * rather than as an error.
     *
     * @return array{ok: bool, domains: array<string, string>, message: string}
     */
    public function domains(string $token): array
    {
        $answer = $this->http->getJson(
            self::BASE . '/domains?limit=' . self::DOMAIN_PAGE,
            $this->auth($token),
        );

        if (!self::succeeded($answer)) {
            return ['ok' => false, 'domains' => [], 'message' => self::reason($answer, 'the domains could not be read')];
        }

        $domains = [];

        foreach ((array)($answer['body']['data'] ?? []) as $domain) {
            if (!\is_array($domain)) {
                continue;
            }

            $id = trim((string)($domain['id'] ?? ''));
            $name = strtolower(trim((string)($domain['name'] ?? '')));

            if ($id !== '' && $name !== '') {
                $domains[$id] = $name;
            }
        }

        return ['ok' => true, 'domains' => $domains, 'message' => ''];
    }

    /**
     * The webhooks already registered on a domain, as `id => url`.
     *
     * @return array{ok: bool, webhooks: array<string, string>, message: string}
     */
    public function webhooks(string $token, string $domainId): array
    {
        $answer = $this->http->getJson(
            self::BASE . '/webhooks?domain_id=' . rawurlencode($domainId),
            $this->auth($token),
        );

        if (!self::succeeded($answer)) {
            return ['ok' => false, 'webhooks' => [], 'message' => self::reason($answer, 'the webhooks could not be read')];
        }

        $webhooks = [];

        foreach ((array)($answer['body']['data'] ?? []) as $webhook) {
            if (!\is_array($webhook)) {
                continue;
            }

            $id = trim((string)($webhook['id'] ?? ''));
            $url = trim((string)($webhook['url'] ?? ''));

            if ($id !== '') {
                $webhooks[$id] = $url;
            }
        }

        return ['ok' => true, 'webhooks' => $webhooks, 'message' => ''];
    }

    /**
     * Create a webhook, or update the one already pointed at this address.
     *
     * The signing secret is the reason this method works the way it does.
     * MailerSend mints one per webhook and hands it back in the answer, and
     * there is no endpoint anywhere that will tell you an existing webhook's
     * secret again — so a secret that is not saved the moment it arrives is a
     * secret that is gone, and the only way back to a working verification is to
     * delete the webhook and make another.
     *
     * @param list<string> $events MailerSend's own event names
     * @return array{ok: bool, id: string|null, secret: string|null, message: string}
     */
    public function saveWebhook(string $token, string $domainId, string $url, string $name, array $events, ?string $webhookId = null): array
    {
        $body = [
            'url' => $url,
            'name' => $name,
            'events' => array_values($events),
            'enabled' => true,
            // Version 2 is MailerSend's lighter payload and the one they
            // recommend. The parser reads both, because a store that had a
            // webhook before this plugin could set one up is on version 1 and
            // nothing here should quietly stop reading its events.
            'version' => 2,
        ];

        if ($webhookId === null) {
            $body['domain_id'] = $domainId;
            $answer = $this->http->sendJson('POST', self::BASE . '/webhooks', $body, $this->auth($token));
        } else {
            // `domain_id` is refused on an update: a webhook cannot be moved
            // between domains, and sending the field is a 422 rather than a
            // no-op.
            $answer = $this->http->sendJson('PUT', self::BASE . '/webhooks/' . rawurlencode($webhookId), $body, $this->auth($token));
        }

        if (!self::succeeded($answer)) {
            return [
                'ok' => false,
                'id' => null,
                'secret' => null,
                'message' => self::reason($answer, 'MailerSend would not save the webhook'),
            ];
        }

        $data = \is_array($answer['body']['data'] ?? null) ? $answer['body']['data'] : [];

        return [
            'ok' => true,
            'id' => self::textOrNull($data['id'] ?? null),
            'secret' => self::textOrNull($data['signing_secret'] ?? null),
            'message' => '',
        ];
    }

    /**
     * What a sending domain's DNS records are meant to say, as MailerSend has
     * them on file for this account.
     *
     * The interesting half is the DKIM hostnames. MailerSend signs with two
     * 2048-bit keys published as CNAMEs at `ms1._domainkey` and
     * `ms2._domainkey`, which reads like a fixed convention until you meet an
     * account still verified with the older single record — so the selectors are
     * read off whatever the account actually has rather than assumed.
     *
     * @return array{selectors?: list<string>, return_paths?: list<string>}
     */
    public function domainFacts(string $token, string $domain): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || trim($token) === '') {
            return [];
        }

        $domains = $this->domains($token);
        $id = array_search($domain, $domains['domains'], true);

        if ($id === false) {
            return [];
        }

        $answer = $this->http->getJson(
            self::BASE . '/domains/' . rawurlencode((string)$id) . '/dns-records',
            $this->auth($token),
        );

        if (!self::succeeded($answer)) {
            return [];
        }

        $data = \is_array($answer['body']['data'] ?? null) ? $answer['body']['data'] : [];

        $selectors = [];
        $returnPaths = [];

        foreach ($data as $key => $record) {
            if (!\is_array($record)) {
                continue;
            }

            $hostname = strtolower(trim((string)($record['hostname'] ?? '')));
            if ($hostname === '') {
                continue;
            }

            if (str_starts_with((string)$key, 'dkim')) {
                $selector = self::selectorIn($hostname);
                if ($selector !== null && !\in_array($selector, $selectors, true)) {
                    $selectors[] = $selector;
                }
            } elseif ((string)$key === 'return_path' && !\in_array($hostname, $returnPaths, true)) {
                $returnPaths[] = $hostname;
            }
        }

        $facts = [];
        if ($selectors !== []) {
            $facts['selectors'] = $selectors;
        }
        if ($returnPaths !== []) {
            $facts['return_paths'] = $returnPaths;
        }

        return $facts;
    }

    // ------------------------------------------------------------- internals

    /** @return array<string, string> */
    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer ' . trim($token)];
    }

    /** @param array{status: int, body: array<array-key, mixed>|null, error: string} $answer */
    private static function succeeded(array $answer): bool
    {
        return $answer['status'] >= 200 && $answer['status'] < 300;
    }

    /**
     * One finished sentence for the merchant out of whichever way MailerSend
     * said no.
     *
     * @param array{status: int, body: array<array-key, mixed>|null, error: string} $answer
     */
    private static function reason(array $answer, string $fallback): string
    {
        if ($answer['status'] === 0) {
            return $answer['error'] !== ''
                ? 'MailerSend could not be reached: ' . $answer['error']
                : 'MailerSend could not be reached.';
        }

        $body = $answer['body'] ?? [];
        $message = trim((string)($body['message'] ?? ''));

        // A 422 puts the useful half under `errors`, one list of sentences per
        // field, and the top-level `message` is always the same eleven words
        // about the data being invalid.
        $details = [];
        foreach ((array)($body['errors'] ?? []) as $field => $sentences) {
            foreach ((array)$sentences as $sentence) {
                $sentence = trim((string)$sentence);
                if ($sentence !== '') {
                    $details[] = $sentence;
                }
            }
            unset($field);
        }

        if ($details !== []) {
            return implode(' ', \array_slice($details, 0, 3));
        }

        if ($answer['status'] === 401) {
            return 'MailerSend did not recognise the API token. Check that it has not expired — a token with no expiry date set is only good for 24 hours.';
        }

        if ($answer['status'] === 403) {
            return $message !== ''
                ? 'MailerSend refused it: ' . $message
                : 'MailerSend refused the API token. It needs Webhooks set to Full access.';
        }

        if ($message !== '') {
            return 'MailerSend refused it: ' . $message;
        }

        return sprintf('%s. MailerSend answered %d.', $fallback, $answer['status']);
    }

    /** `ms1` out of `ms1._domainkey.example.com`. */
    private static function selectorIn(string $hostname): ?string
    {
        $first = explode('.', $hostname)[0] ?? '';
        $first = trim($first);

        return $first === '' ? null : $first;
    }

    private static function textOrNull(mixed $value): ?string
    {
        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }

        $value = trim((string)$value);

        return $value === '' ? null : $value;
    }
}
