<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Provider;

use Grav\Plugin\Email\Providers\Inbound\Address;
use Grav\Plugin\Email\Providers\Inbound\InboundAttachment;
use Grav\Plugin\Email\Providers\Inbound\InboundMessage;
use Grav\Plugin\Email\Providers\Inbound\InboundPayload;
use Grav\Plugin\Email\Providers\Inbound\InboundReceiver;
use Grav\Plugin\Email\Providers\Inbound\InboundReference;
use Grav\Plugin\Email\Providers\Inbound\InboundRequest;
use Grav\Plugin\Email\Providers\Verdict;
use Grav\Plugin\Email\Providers\WebhookRequest;

/**
 * Mail sent to a MailerSend inbound route, read.
 *
 * Documentation: `developers.mailersend.com/api/v1/inbound.html` (the route
 * object, the endpoint check and "Webhook payload example") and
 * `developers.mailersend.com/api/v1/webhooks.html` (Security, Retrying failed
 * webhooks), read 2026-09-23.
 *
 * This is the only class in the plugin that names the Email plugin's inbound
 * types, and nothing loads it unless that plugin has them: see
 * {@see MailerSendInboundProvider}.
 *
 * ## The payload
 *
 *     {type: "inbound.message", inbound_id, url, created_at, data: {
 *         object: "message", id,
 *         recipients: {to: {raw, data: [{name, email}]}, rcptTo: [{email}]},
 *         from: {raw, name, email}, sender: {email},
 *         subject, date, headers: {Name: value},
 *         text, html, raw, attachments: [],
 *         spf_check: {code, value}, dkim_check: bool, created_at}}
 *
 * The whole message arrives in one post, so there is nothing to fetch. `raw`
 * is the message as received, and it is what the message is built from through
 * {@see InboundMessage::fromMime()} whenever it reads as a message; the fields
 * are the fallback. `recipients.rcptTo` is the SMTP envelope, which is where a
 * `support+token@` address survives when the visible To has lost it, and
 * `sender.email` is the envelope sender.
 *
 * MailerSend's documented sample has an empty `attachments` list and says
 * nothing about an entry's fields, so {@see attachment()} reads the spellings
 * MailerSend uses elsewhere and the obvious alternatives. With a raw message
 * none of that matters: the attachments come out of the MIME.
 *
 * ## Authentication results
 *
 * `spf_check.code` is the SPF qualifier (`+` pass, `-` fail, `~` softfail,
 * `?` neutral) and `dkim_check` a boolean. The boolean does not say whether a
 * message was unsigned or failed its check, so `false` is written as `none`,
 * "no passing signature", rather than as a failure. Both are laid over whatever
 * the message's own `Authentication-Results` said, which still supplies DMARC
 * where it has it.
 *
 * ## The signature
 *
 * The same `Signature` header as MailerSend's activity webhooks, an
 * HMAC-SHA256 of the raw body, so it goes through
 * {@see MailerSendReports::verify()}. The key is different: every webhook
 * forward on an inbound route has a `secret` of its own, separate from the
 * delivery webhook's signing secret, so it is kept under {@see SECRET_KEY}.
 *
 * Saving a route makes MailerSend post a `webhook.test` ping to the address,
 * signed with the fixed test secret from their documentation, and the route is
 * not saved unless that answers 2xx. That check accepts the ping, and only the
 * ping, under the public secret, exactly as for delivery webhooks, and
 * {@see parse()} answers it with nothing.
 *
 * No timestamp is signed, and MailerSend retries a failed post for about three
 * days, so there is no freshness window to check. A replayed request carries
 * the same message id, which the consumer's dedupe catches.
 */
final class MailerSendInbound implements InboundReceiver
{
    public const KEY = 'mailersend';

    /** The config key the inbound route's webhook secret is kept under. */
    public const SECRET_KEY = 'inbound_signing_secret';

    /** The one event this receiver reads. */
    public const EVENT = 'inbound.message';

    /**
     * The largest body accepted.
     *
     * MailerSend documents no inbound size limit. The post carries the message
     * twice over, once as `raw` and once as the parsed fields with any
     * attachments base64-encoded, so this is generous on purpose: a 413 is a 4xx,
     * and MailerSend does not retry a 4xx, so the message would be lost.
     */
    public const MAX_BYTES = 80 * 1024 * 1024;

    /**
     * SPF qualifiers into the words an `Authentication-Results` header uses.
     *
     * @var array<string, string>
     */
    public const SPF = [
        '+' => 'pass',
        '-' => 'fail',
        '~' => 'softfail',
        '?' => 'neutral',
    ];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return MailerSendProvider::LABEL;
    }

    /**
     * The inbound route's own webhook secret. Not the delivery webhook's
     * signing secret: MailerSend gives each route forward a secret of its own.
     *
     * @return list<string>
     */
    public function verificationKeys(): array
    {
        return [self::SECRET_KEY];
    }

    public function maxBytes(): int
    {
        return self::MAX_BYTES;
    }

    public function verify(InboundRequest $request, array $config): Verdict
    {
        $secret = trim((string)($config[self::SECRET_KEY] ?? ''));

        $verdict = (new MailerSendReports())->verify(
            new WebhookRequest(
                $request->method,
                $request->path,
                $request->query,
                $request->headers,
                $request->body,
                $request->remoteAddress,
            ),
            ['signing_secret' => $secret]
        );

        // Without a secret only the endpoint check passes, which is what lets a
        // route be saved before its secret has been copied across.
        if (!$verdict->ok && $secret === '' && $request->header(MailerSendReports::SIGNATURE_HEADER) !== '') {
            return Verdict::refused('no MailerSend inbound signing secret is configured, so only the endpoint check can be accepted');
        }

        return $verdict;
    }

    public function parse(InboundRequest $request, array $config): InboundPayload
    {
        try {
            $body = $request->json();
            if ($body === null || ($body !== [] && array_is_list($body))) {
                return InboundPayload::unreadable('the body was not a JSON object');
            }

            $type = strtolower(self::text($body['type'] ?? null));
            if ($type === MailerSendReports::TEST_TYPE) {
                return InboundPayload::nothing('MailerSend checking that this address answers, which is how an inbound route is saved');
            }
            if ($type !== self::EVENT) {
                return InboundPayload::nothing(sprintf('MailerSend reported "%s", which is not a received message', $type));
            }

            $data = \is_array($body['data'] ?? null) ? $body['data'] : null;
            if ($data === null) {
                return InboundPayload::unreadable('the inbound.message event carried no data object');
            }

            return InboundPayload::of([self::message($data)]);
        } catch (\Throwable $e) {
            return InboundPayload::unreadable('the inbound.message event could not be read: ' . $e->getMessage());
        }
    }

    /**
     * Never called: MailerSend posts the whole message.
     *
     * @throws \LogicException
     */
    public function fetch(InboundReference $ref, array $config): InboundMessage
    {
        throw new \LogicException('MailerSend posts whole messages, so there is nothing to fetch.');
    }

    public function instructions(string $webhookUrl): string
    {
        return 'In MailerSend, open Domains, click Manage beside your domain, scroll to Inbound routing and press Add an inbound route. '
            . 'Name it, then either use the inbound address MailerSend gives you (forward your support mailbox to it) or turn on '
            . 'inbound domain forwarding for a subdomain such as support.example.com and add the MX record MailerSend shows to your DNS. '
            . 'Leave the catch and match filters on all, or narrow them to your support address. '
            . 'Under Route to, choose Webhook and paste ' . $webhookUrl . '. MailerSend checks the address before it saves the route. '
            . 'Once it is saved, copy the route\'s webhook secret into the Inbound signing secret field in the Email MailerSend plugin. '
            . 'It is a different secret from the delivery webhook\'s signing secret. Keep attachments included, or pictures pasted into replies are lost.';
    }

    // ------------------------------------------------------------- internals

    /** @param array<array-key, mixed> $data */
    private static function message(array $data): InboundMessage
    {
        $overrides = [];

        $id = self::text($data['id'] ?? null);
        if ($id !== '') {
            $overrides['providerId'] = $id;
        }

        $recipients = \is_array($data['recipients'] ?? null) ? $data['recipients'] : [];
        $envelope = [];
        foreach (\is_array($recipients['rcptTo'] ?? null) ? $recipients['rcptTo'] : [] as $rcpt) {
            $email = strtolower(self::text(\is_array($rcpt) ? ($rcpt['email'] ?? null) : $rcpt));
            if ($email !== '' && !\in_array($email, $envelope, true)) {
                $envelope[] = $email;
            }
        }
        if ($envelope !== []) {
            $overrides['envelopeTo'] = $envelope;
        }

        $sender = \is_array($data['sender'] ?? null) ? self::text($data['sender']['email'] ?? null) : '';
        if ($sender !== '') {
            $overrides['envelopeFrom'] = $sender;
        }

        $raw = self::raw($data['raw'] ?? null);
        $message = $raw !== null
            ? InboundMessage::fromMime($raw, self::KEY, $overrides)
            : self::fromFields($data)->with($overrides);

        $auth = self::auth($data);

        return $auth === [] ? $message : $message->with(['auth' => $auth + $message->auth]);
    }

    /**
     * The raw message, when `raw` reads as one. Base64 is accepted as well,
     * because an API that has sent bytes both ways over the years will do it
     * again.
     */
    private static function raw(mixed $value): ?string
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }
        if (self::looksLikeMime($value)) {
            return $value;
        }
        $decoded = base64_decode(preg_replace('/\s+/', '', $value) ?? '', true);

        return \is_string($decoded) && self::looksLikeMime($decoded) ? $decoded : null;
    }

    private static function looksLikeMime(string $value): bool
    {
        return preg_match('/^(?:From |[A-Za-z0-9-]+:[^\r\n]*\r?\n)/', ltrim($value)) === 1;
    }

    /** @param array<array-key, mixed> $data */
    private static function fromFields(array $data): InboundMessage
    {
        $headers = [];
        foreach (\is_array($data['headers'] ?? null) ? $data['headers'] : [] as $name => $value) {
            if (!\is_string($name) || $name === '') {
                continue;
            }
            foreach (\is_array($value) ? $value : [$value] as $one) {
                if (\is_scalar($one)) {
                    $headers[] = [$name, (string)$one];
                }
            }
        }
        $header = static function (string $name) use ($headers): string {
            foreach ($headers as [$key, $value]) {
                if (strtolower($key) === $name) {
                    return trim($value);
                }
            }

            return '';
        };

        $fromField = \is_array($data['from'] ?? null) ? $data['from'] : [];
        $from = self::text($fromField['email'] ?? null) !== ''
            ? new Address(self::text($fromField['email']), self::text($fromField['name'] ?? null))
            : Address::parse($header('from') ?: self::text($fromField['raw'] ?? null));

        $recipients = \is_array($data['recipients'] ?? null) ? $data['recipients'] : [];

        $text = \is_string($data['text'] ?? null) ? $data['text'] : null;
        $html = \is_string($data['html'] ?? null) ? $data['html'] : null;

        $contentType = strtolower(trim(explode(';', $header('content-type'))[0]));
        if ($contentType === '') {
            $contentType = $html !== null && $text !== null
                ? 'multipart/alternative'
                : ($html !== null ? 'text/html' : 'text/plain');
        }

        $date = null;
        foreach ([self::text($data['date'] ?? null), $header('date'), self::text($data['created_at'] ?? null)] as $when) {
            $at = $when === '' ? false : strtotime($when);
            if ($at !== false) {
                $date = $at;
                break;
            }
        }

        $attachments = [];
        foreach (\is_array($data['attachments'] ?? null) ? $data['attachments'] : [] as $row) {
            $attachment = \is_array($row) ? self::attachment($row) : null;
            if ($attachment !== null) {
                $attachments[] = $attachment;
            }
        }

        return new InboundMessage(
            messageId: self::id($header('message-id')) ?: null,
            inReplyTo: self::ids($header('in-reply-to'))[0] ?? null,
            references: self::ids($header('references')),
            from: $from,
            to: self::addresses($recipients['to'] ?? null, $header('to')),
            cc: self::addresses($recipients['cc'] ?? null, $header('cc')),
            replyTo: Address::parseList($header('reply-to')),
            subject: self::text($data['subject'] ?? null) ?: $header('subject'),
            date: $date,
            text: $text,
            html: $html,
            headers: $headers,
            attachments: $attachments,
            receiver: self::KEY,
            contentType: $contentType,
        );
    }

    /**
     * One entry from `attachments`, base64 content and all, or null when it
     * carries no content.
     *
     * @param array<array-key, mixed> $row
     */
    private static function attachment(array $row): ?InboundAttachment
    {
        $content = $row['content'] ?? $row['data'] ?? null;
        if (!\is_string($content) || $content === '') {
            return null;
        }
        $bytes = base64_decode(preg_replace('/\s+/', '', $content) ?? '', true);
        if ($bytes === false) {
            return null;
        }

        $name = self::text($row['filename'] ?? $row['file_name'] ?? $row['name'] ?? null);
        $name = basename((string)preg_replace('/[\x00-\x1F\x7F]/', '', str_replace('\\', '/', $name)));
        $cid = trim(self::text($row['content_id'] ?? $row['contentId'] ?? $row['cid'] ?? null), " \t<>");
        $disposition = strtolower(self::text($row['disposition'] ?? $row['content_disposition'] ?? null));

        return new InboundAttachment(
            filename: $name === '.' || $name === '..' ? '' : $name,
            contentType: strtolower(self::text($row['content_type'] ?? $row['contentType'] ?? $row['type'] ?? null)) ?: 'application/octet-stream',
            size: \strlen($bytes),
            contentId: $cid === '' ? null : $cid,
            inline: $disposition === 'inline' || ($disposition === '' && $cid !== ''),
            content: $bytes,
        );
    }

    /**
     * `spf_check` and `dkim_check`, in the contract's words. See the class note.
     *
     * @param  array<array-key, mixed> $data
     * @return array<string, string>
     */
    private static function auth(array $data): array
    {
        $auth = [];

        $spf = \is_array($data['spf_check'] ?? null) ? self::text($data['spf_check']['code'] ?? null) : '';
        if (isset(self::SPF[$spf])) {
            $auth['spf'] = self::SPF[$spf];
        }

        if (\is_bool($data['dkim_check'] ?? null)) {
            $auth['dkim'] = $data['dkim_check'] ? 'pass' : 'none';
        }

        return $auth;
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * The addresses in one of the `recipients` blocks, `{raw, data: [{name, email}]}`,
     * or the header when the block is missing.
     *
     * @return list<Address>
     */
    private static function addresses(mixed $block, string $header): array
    {
        $out = [];
        if (\is_array($block) && \is_array($block['data'] ?? null)) {
            foreach ($block['data'] as $row) {
                $email = \is_array($row) ? self::text($row['email'] ?? null) : '';
                if ($email !== '') {
                    $out[] = new Address($email, self::text($row['name'] ?? null));
                }
            }
        }
        if ($out === [] && \is_array($block) && self::text($block['raw'] ?? null) !== '') {
            $out = Address::parseList(self::text($block['raw']));
        }

        return $out !== [] ? $out : Address::parseList($header);
    }

    /** One message id without its angle brackets, lower-cased. */
    private static function id(string $value): string
    {
        return strtolower(trim($value, " \t\r\n<>"));
    }

    /**
     * Every message id in an In-Reply-To or References header.
     *
     * @return list<string>
     */
    private static function ids(string $value): array
    {
        if (preg_match_all('/<([^<>\s]+)>/', $value, $matches) > 0) {
            return array_values(array_map('strtolower', $matches[1]));
        }

        $id = self::id($value);

        return $id === '' || preg_match('/\s/', $id) === 1 ? [] : [$id];
    }
}
