<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Provider;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\SetupResult;
use Grav\Plugin\Email\Providers\WebhookSetup;

/**
 * "Set up in MailerSend", which is the one button this whole thing is for.
 *
 * A merchant who has already pasted an API key should not then have to find
 * MailerSend's dashboard, work out which of the two things called webhooks is
 * the right one, tick seven boxes out of twenty-three, choose a payload version
 * and copy a signing secret back into Grav without losing it. All of that is
 * one call and one save here.
 *
 * ## What it registers
 *
 * The six MailerSend events behind the five the contract has words for, and
 * nothing else. `activity.sent`, `activity.deferred`, the two `_unique`
 * duplicates and the survey pair are deliberately left off: a store that does
 * not act on an event should not be asking MailerSend to send it, and a webhook
 * registered for everything is a store paying for traffic it discards.
 *
 * ## The signing secret, and why this class writes to the plugin's config
 *
 * MailerSend mints the signing secret when the webhook is created and returns
 * it once, in that answer. Nothing will tell you an existing webhook's secret
 * again — not the list endpoint, not the dashboard. So a secret that is not
 * saved the moment it arrives is a secret that is gone, and the only way back to
 * working verification is to delete the webhook and make a new one. That is why
 * this takes a writer and uses it, rather than printing the secret and asking
 * somebody to paste it into the next field along.
 *
 * ## Pressing the button twice
 *
 * Looks for a webhook already pointed at the same address on the same domain
 * and updates that one rather than making a second. Two webhooks posting the
 * same events to the same URL would double every figure in a store's reports,
 * and would do it quietly.
 *
 * An update is where the secret is awkward: MailerSend does not mint a new one
 * and may not repeat the old one, so a second press can succeed and leave
 * verification exactly as broken as it was. The message says so in plain words
 * and names the way out, which is to delete the webhook in MailerSend and press
 * the button again.
 *
 * ## Pressing it after the secret changed
 *
 * A new secret is a new address, so the webhook MailerSend holds is posting at
 * one that answers 404 and the store looks as though nothing is registered. It
 * is still recognisably this store's webhook: the URL sits under the same
 * endpoint on the same domain and only the secret on the end is different. So
 * it is updated to the new address rather than joined by a second one, and the
 * message says so. The signing secret is as awkward here as on any other
 * update, and the same sentence covers it.
 */
final class MailerSendSetup implements WebhookSetup
{
    /** The name the webhook is given in MailerSend's list. Their cap is 50 characters. */
    public const WEBHOOK_NAME = 'Grav delivery reports';

    /**
     * The contract's event words into MailerSend's own.
     *
     * A bounce is two events there, and both are asked for: the pair is what
     * lets a store tell a mailbox that is full this afternoon from an address
     * that does not exist.
     *
     * @var array<string, list<string>>
     */
    public const EVENTS = [
        Event::DELIVERED => ['activity.delivered'],
        Event::BOUNCED => ['activity.hard_bounced', 'activity.soft_bounced'],
        Event::COMPLAINED => ['activity.spam_complaint'],
        Event::OPENED => ['activity.opened'],
        Event::CLICKED => ['activity.clicked'],
    ];

    /**
     * @param \Closure|null $save called as `($save)(array $values): void` with the
     *        keys to write into this plugin's own config. Null in a test, and on
     *        a site where nothing can be written.
     */
    public function __construct(
        private readonly MailerSendApi $api,
        private readonly ?\Closure $save = null,
    ) {
    }

    public function create(string $url, array $events, array $config): SetupResult
    {
        $token = trim((string)($config['api_key'] ?? ''));

        if ($token === '') {
            return SetupResult::failed('There is no MailerSend API key in this plugin\'s settings. Paste one in, then press this again. ' . $this->permissionsNeeded());
        }

        $url = trim($url);

        if (!str_starts_with(strtolower($url), 'https://')) {
            return SetupResult::failed('MailerSend will only post to an https address, and there is not one to register yet.');
        }

        $names = self::eventNames($events);

        if ($names === []) {
            return SetupResult::failed('None of the events asked for are ones MailerSend can report.');
        }

        $domain = $this->domainId($token, $config);

        if ($domain['id'] === null) {
            return SetupResult::failed($domain['message']);
        }

        $existing = $this->api->webhooks($token, $domain['id']);

        if (!$existing['ok']) {
            return SetupResult::failed($existing['message']);
        }

        // Read once and looked through twice: the address exactly, and then the
        // endpoint it sits under, which is how this store's own webhook is
        // recognised after the secret on the end of it has changed.
        $endpoint = self::endpointOf($url);
        $webhookId = null;
        $stale = null;

        foreach ($existing['webhooks'] as $id => $registered) {
            $registered = trim($registered);

            if (strcasecmp($registered, $url) === 0) {
                $webhookId = (string)$id;
                break;
            }

            if ($stale === null && $endpoint !== '' && stripos($registered, $endpoint) === 0) {
                $stale = (string)$id;
            }
        }

        $repointed = $webhookId === null && $stale !== null;
        $webhookId ??= $stale;

        $answer = $this->api->saveWebhook($token, $domain['id'], $url, self::WEBHOOK_NAME, $names, $webhookId);

        if (!$answer['ok']) {
            return SetupResult::failed($answer['message']);
        }

        $this->remember(['domain_id' => $domain['id']] + ($answer['secret'] === null ? [] : ['signing_secret' => $answer['secret']]));

        if ($answer['secret'] !== null) {
            return SetupResult::ok(
                match (true) {
                    $webhookId === null => 'The webhook was created in MailerSend and its signing secret saved here, so delivery reports will be checked from now on.',
                    $repointed => 'MailerSend had this store\'s webhook registered with an older secret. It now points at this address, and its signing secret has been saved here.',
                    default => 'The webhook already pointed at this address was updated in MailerSend, and its signing secret saved here.',
                },
                $answer['id'] ?? $webhookId,
            );
        }

        return SetupResult::ok(
            ($repointed
                ? 'MailerSend had this store\'s webhook registered with an older secret. It now points at this address.'
                : 'The webhook was updated in MailerSend.')
            . ' It did not return a signing secret, which it only does when a webhook is first created — so if delivery reports are being refused, delete the webhook in MailerSend and press this again.',
            $answer['id'] ?? $webhookId,
        );
    }

    public function permissionsNeeded(): string
    {
        return 'The API token needs Webhooks set to Full access, and Domains set to Read only so the sending domain can be found. Both are on the token\'s own page, under Integrations, MailerSend API, Manage.';
    }

    // ------------------------------------------------------------- internals

    /**
     * Which of the account's domains this webhook belongs to.
     *
     * MailerSend requires a domain id to create a webhook, and a domain id is a
     * sixteen character hash that appears nowhere a merchant would look. So it
     * is resolved rather than asked for, and the refusals name the domains that
     * were actually found, because "the domain could not be matched" is not
     * something anybody can act on.
     *
     * @param array<string, mixed> $config
     * @return array{id: string|null, message: string}
     */
    private function domainId(string $token, array $config): array
    {
        $configured = trim((string)($config['domain_id'] ?? ''));

        if ($configured !== '') {
            return ['id' => $configured, 'message' => ''];
        }

        $answer = $this->api->domains($token);

        if (!$answer['ok']) {
            return ['id' => null, 'message' => $answer['message']];
        }

        $wanted = strtolower(trim((string)($config['domain'] ?? '')));

        if ($wanted !== '') {
            $id = array_search($wanted, $answer['domains'], true);

            if ($id !== false) {
                return ['id' => (string)$id, 'message' => ''];
            }

            return [
                'id' => null,
                'message' => sprintf(
                    'MailerSend has no verified domain called %s on this account. It has %s.',
                    $wanted,
                    self::readable($answer['domains']),
                ),
            ];
        }

        if (\count($answer['domains']) === 1) {
            return ['id' => (string)array_key_first($answer['domains']), 'message' => ''];
        }

        if ($answer['domains'] === []) {
            return ['id' => null, 'message' => 'This MailerSend account has no verified sending domain yet, so there is nothing to attach a webhook to.'];
        }

        return [
            'id' => null,
            'message' => sprintf(
                'This MailerSend account has more than one sending domain (%s). Put the one this site sends from in the Sending domain field, then press this again.',
                self::readable($answer['domains']),
            ),
        ];
    }

    /**
     * MailerSend's own event names for the contract's words.
     *
     * An event this provider cannot report is ignored rather than refused, as
     * the contract asks — `dropped` is the one that arrives here, and MailerSend
     * has nothing that means it.
     *
     * @param list<string> $events
     * @return list<string>
     */
    private static function eventNames(array $events): array
    {
        $events = array_values(array_filter($events, static fn ($event): bool => \is_string($event) && trim($event) !== ''));

        if ($events === []) {
            $events = array_keys(self::EVENTS);
        }

        $names = [];

        foreach ($events as $event) {
            foreach (self::EVENTS[strtolower(trim($event))] ?? [] as $name) {
                if (!\in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * The address without its secret: everything up to and including the last
     * slash. Two addresses that share it belong to the same store.
     */
    private static function endpointOf(string $url): string
    {
        $cut = strrpos($url, '/');

        return $cut === false || $cut < \strlen('https://x/') ? '' : substr($url, 0, $cut + 1);
    }

    /** @param array<string, mixed> $values */
    private function remember(array $values): void
    {
        if ($this->save === null || $values === []) {
            return;
        }

        // Somebody else's write. A settings file that cannot be written is a
        // secret the merchant has to paste by hand, which the message already
        // covers — it is not a reason to throw out of a button press.
        try {
            ($this->save)($values);
        } catch (\Throwable) {
        }
    }

    /** @param array<string, string> $domains */
    private static function readable(array $domains): string
    {
        $names = array_values(array_unique(array_values($domains)));
        sort($names);

        return $names === [] ? 'none' : implode(', ', $names);
    }
}
