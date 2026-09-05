<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Provider;

use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\Payload;
use Grav\Plugin\Email\Providers\SendHeader;
use Grav\Plugin\Email\Providers\Verdict;
use Grav\Plugin\Email\Providers\WebhookRequest;

/**
 * MailerSend's delivery webhook, verified and read.
 *
 * Documentation: `developers.mailersend.com/api/v1/account/webhooks` and
 * `mailersend.com/whats-new/webhooks-v2`, read 2026-09-05.
 *
 * ## Two payload versions, and the field that changes type between them
 *
 * MailerSend has two webhook payloads and a webhook is created with a `version`
 * of 1 or 2. Version 2 is lighter, is what they recommend, and is what this
 * plugin's setup button asks for; version 1 is what every webhook made before
 * it exists is still on, and a store upgrading this plugin must not quietly
 * stop reading its own events. So both are read.
 *
 *     version 1: {type, domain_id, created_at, webhook_id, url, data: {
 *                    object, id, type, created_at, morph,
 *                    email: {id, from, subject, status, tags,
 *                            message: {id}, recipient: {id, email}}}}
 *
 *     version 2: {type, created_at, data: {
 *                    id, domain_id, message_id, email_id, type,
 *                    subject, email, tags, meta}}
 *
 * **`data.email` is an object in version 1 and a string in version 2**, and that
 * is what tells the two apart here. It is also the trap: a reader that took
 * `data.email` for an address would get an array for every event on a version 1
 * webhook and quietly record nothing. MailerSend's own two pages disagree about
 * the version 2 spelling as well — the API reference calls it `email` and the
 * release note calls it `recipient` — so both are read, which costs one line.
 *
 * ## Correlation, and the honest answer about it
 *
 * MailerSend echoes no headers in any webhook payload, in either version. There
 * is no header list, no metadata object and no user variables, and the
 * `Message-ID` the sender put on the message is not returned either. What comes
 * back is MailerSend's own message id — `data.email.message.id` in version 1,
 * `data.message_id` in version 2 — which is the same id their SMTP relay
 * answers with in `250 Message queued as …` and the same one their Email API
 * hands back in the `x-message-id` response header.
 *
 * So {@see Event::$sendId} is always null here and {@see Event::$messageId} is
 * too, and {@see Event::$providerId} carries the only handle there is. A store
 * that recorded what the transport told it the message id was can join on that;
 * a store that recorded its own `Message-ID` cannot, and its bounces and spam
 * complaints will be matched by recipient address instead, which is enough to
 * keep a suppression list right even when it is not enough to fill in a per-send
 * chart. That is said plainly on the provider's capabilities rather than left
 * for somebody to discover from an empty column.
 *
 * ## Nothing here is ever `dropped`
 *
 * The contract's sixth word is for a message a provider refused to send at all,
 * and MailerSend has no event for that. `activity.hard_bounced` is a receiving
 * server refusing the address, which is a hard bounce and is reported as one.
 * `recipient.on_hold_added` is the nearest thing and is not it either: their
 * hold is three days with an `on_hold_until` on it, and turning a three-day
 * hold into a permanent suppression would lose customers. A send to an address
 * MailerSend has already suppressed is refused by the API call itself rather
 * than reported afterwards, so there is nothing here to map.
 *
 * ## The signature, and the endpoint check that is signed with a public secret
 *
 * `Signature: <hex>`, an HMAC-SHA256 over the raw request body keyed with the
 * webhook's own signing secret. No timestamp is signed and none is sent, so
 * there is no freshness window to check and — worth knowing rather than
 * hiding — a captured request stays replayable forever. What stands between a
 * replay and a store is the secret in the webhook address, which is why that is
 * long and why the route compares it in constant time.
 *
 * The one real subtlety is MailerSend's endpoint check. Creating or updating a
 * webhook makes MailerSend POST a `webhook.test` body to the address first, and
 * **the webhook is not saved unless that gets a 2xx**. It is signed with a fixed
 * secret that is printed in their documentation — `test_Am3L1Gu…` — because at
 * that moment the real secret does not exist yet. Refusing it would mean no
 * webhook could ever be created.
 *
 * A published secret is not a secret, so it is accepted only for the ping and
 * only after the real key has already failed: the real signature is checked over
 * the raw bytes first, and the body is not decoded until one of the two
 * signatures has matched. A `webhook.test` under the test secret answers
 * {@see Payload::nothing()} and touches nothing, so the worst a stranger holding
 * a public string can achieve is a log line.
 */
final class MailerSendReports implements DeliveryReports
{
    /** The header MailerSend signs with. Read case insensitively either way. */
    public const SIGNATURE_HEADER = 'Signature';

    /**
     * The secret MailerSend signs its endpoint check with, from their own
     * documentation. Public on purpose; see the class note.
     */
    public const TEST_SECRET = 'test_Am3L1GuOIc4blLUuHqAPxxwkZaJyEk8G';

    /** The body type of that check. */
    public const TEST_TYPE = 'webhook.test';

    /**
     * Nothing before this is a real moment. 2000-01-01, which catches a
     * provider's own zero placeholder and a field that was not a date at all.
     */
    public const MOMENT_FLOOR = 946684800;

    /**
     * MailerSend's event names into the contract's, and what each says about a
     * bounce being permanent.
     *
     * @var array<string, array{0: string, 1: bool|null}>
     */
    public const TYPES = [
        'activity.delivered' => [Event::DELIVERED, null],
        'activity.hard_bounced' => [Event::BOUNCED, true],
        'activity.soft_bounced' => [Event::BOUNCED, false],
        'activity.spam_complaint' => [Event::COMPLAINED, null],
        'activity.opened' => [Event::OPENED, null],
        'activity.clicked' => [Event::CLICKED, null],
    ];

    /**
     * Events MailerSend sends that this reader knows about and skips, with the
     * reason, so a merchant reading a log line gets an answer rather than a
     * shrug.
     *
     * `activity.sent` is the one people are surprised by: it means the message
     * left MailerSend's servers and they are now waiting on the receiving
     * side, which is not the same claim as `activity.delivered` and is not
     * worth a row. The `_unique` pair are second copies of an open or a click
     * that already arrived, and acting on both would double every figure.
     *
     * @var array<string, string>
     */
    public const SKIPPED = [
        'activity.sent' => 'the message left MailerSend and they are waiting on the receiving server',
        'activity.deferred' => 'the receiving server asked MailerSend to try later',
        'activity.opened_unique' => 'a first-time open, which already arrived as an open',
        'activity.clicked_unique' => 'a first-time click, which already arrived as a click',
        'activity.unsubscribed' => 'an unsubscribe made through MailerSend rather than through this store',
        'activity.survey_opened' => 'a survey event',
        'activity.survey_submitted' => 'a survey event',
        'recipient.on_hold_added' => 'an address put on MailerSend\'s three-day hold, which is not a permanent failure',
        'recipient.on_hold_removed' => 'an address taken off MailerSend\'s hold',
    ];

    public function events(): array
    {
        return [Event::DELIVERED, Event::BOUNCED, Event::COMPLAINED, Event::OPENED, Event::CLICKED];
    }

    public function verificationKeys(): array
    {
        return ['signing_secret'];
    }

    public function verify(WebhookRequest $request, array $config): Verdict
    {
        $signature = strtolower(trim($request->header(self::SIGNATURE_HEADER)));

        if ($signature === '') {
            return Verdict::refused('the request carried no Signature header');
        }

        $secret = trim((string)($config['signing_secret'] ?? ''));

        // The real key first, over the raw bytes, before anything has looked at
        // the body as anything but a string. An HMAC over a body that was
        // decoded and re-encoded on the way here would not match however right
        // the key is.
        if ($secret !== '' && hash_equals(hash_hmac('sha256', $request->body, $secret), $signature)) {
            return Verdict::verified();
        }

        // Only now, and only for the endpoint check. See the class note.
        if (hash_equals(hash_hmac('sha256', $request->body, self::TEST_SECRET), $signature)) {
            return self::isEndpointCheck($request)
                ? Verdict::verified()
                : Verdict::refused('a body that was not the endpoint check arrived signed with MailerSend\'s public test secret');
        }

        if ($secret === '') {
            return Verdict::refused('no MailerSend signing secret is configured, so only the endpoint check can be accepted');
        }

        return Verdict::refused('the MailerSend signature did not match');
    }

    public function parse(WebhookRequest $request): Payload
    {
        $body = $request->json();

        if ($body === null) {
            return Payload::unreadable('the body was not a JSON object');
        }

        $type = strtolower(trim((string)($body['type'] ?? '')));

        if ($type === '') {
            return Payload::unreadable('the body carried no event type');
        }

        if ($type === self::TEST_TYPE) {
            return Payload::nothing('MailerSend checking that this address answers, which is how a webhook is saved');
        }

        if (isset(self::SKIPPED[$type])) {
            return Payload::nothing(sprintf('MailerSend reported "%s": %s', $type, self::SKIPPED[$type]));
        }

        $mapped = self::TYPES[$type] ?? null;

        if ($mapped === null) {
            return Payload::nothing(sprintf('MailerSend reported "%s", which this store does not act on', $type));
        }

        $data = \is_array($body['data'] ?? null) ? $body['data'] : null;

        if ($data === null) {
            return Payload::unreadable('the body carried no data object');
        }

        [$ourType, $hard] = $mapped;

        // Version 1 nests an email object here; version 2 puts the recipient's
        // address in the same key as a plain string. See the class note.
        $email = \is_array($data['email'] ?? null) ? $data['email'] : null;

        return Payload::of([Event::of(
            $ourType,
            $hard,
            $email !== null
                ? (string)($email['recipient']['email'] ?? '')
                : (string)($data['email'] ?? $data['recipient'] ?? ''),
            // MailerSend never returns the Message-ID the sender set.
            null,
            $email !== null
                ? (string)($email['message']['id'] ?? '')
                : (string)($data['message_id'] ?? ''),
            self::moment($data['created_at'] ?? null) ?? self::moment($body['created_at'] ?? null) ?? 0,
            self::reason($data, $ourType),
            // MailerSend echoes no header and no metadata, in either version.
            null,
        )]);
    }

    /**
     * The name the Email plugin answers, which is `X-Grav-Send-Id` unless the
     * site says otherwise.
     *
     * For MailerSend it is documentation and nothing else: the header goes out
     * on the message and never comes back. See the class note.
     */
    public function sendHeader(): string
    {
        return SendHeader::name();
    }

    // ------------------------------------------------------------- internals

    /**
     * Whether this body is MailerSend's endpoint check.
     *
     * Only ever called after a signature has already matched, which is the
     * whole point of where it sits.
     */
    private static function isEndpointCheck(WebhookRequest $request): bool
    {
        $body = $request->json();

        return strtolower(trim((string)($body['type'] ?? ''))) === self::TEST_TYPE;
    }

    /**
     * MailerSend's own words about why, where there are any.
     *
     * There are frequently none. MailerSend documents `data.morph` on version 1
     * only as `null` and documents `data.meta` on version 2 only for surveys,
     * and neither page says what a bounce puts there — so both are read
     * defensively for the two keys a reason could plausibly be under, and an
     * empty answer is normal rather than a sign that something went wrong. A
     * spam complaint gets a sentence of our own, because "this person pressed
     * the spam button" is the whole story and MailerSend has nothing to add to
     * it.
     *
     * @param array<array-key, mixed> $data
     */
    private static function reason(array $data, string $type): ?string
    {
        foreach (['morph', 'meta'] as $key) {
            $block = \is_array($data[$key] ?? null) ? $data[$key] : [];

            foreach (['reason', 'message'] as $field) {
                $value = $block[$field] ?? null;

                if (\is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return $type === Event::COMPLAINED ? 'marked as spam' : null;
    }

    /**
     * A moment in whole seconds out of MailerSend's two date spellings.
     *
     * They send `2025-08-05T21:23:54.000000Z` on some events and
     * `2025-08-05 22:27:14` on others, and `strtotime` reads both. Answering
     * null for anything else rather than `time()` is the point: the receiver
     * stamps a null with the moment the request arrived and it is the one with
     * a clock, and a parser that reached for `time()` is a parser no test can
     * pin.
     */
    private static function moment(mixed $value): ?int
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        $at = strtotime(trim($value));

        return $at === false || $at < self::MOMENT_FLOOR ? null : $at;
    }
}
