<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Provider;

use Grav\Plugin\Email\Providers\Capabilities;
use Grav\Plugin\Email\Providers\DeliveryReports;
use Grav\Plugin\Email\Providers\DomainFacts;
use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\Email\Providers\WebhookSetup;
use Grav\Plugin\EmailMailersend\Http\CurlHttp;
use Grav\Plugin\EmailMailersend\Http\Http;

/**
 * Everything this plugin knows about MailerSend, answered to the Email plugin's
 * provider contract.
 *
 * Registered from `onEmailProviders` with this plugin's own config handed in,
 * which is where the API key and the signing secret already live. Nothing else
 * on the site carries a MailerSend field name, a MailerSend event name or a
 * MailerSend DNS host any more.
 *
 * Everything here is Grav-free and does no I/O beyond the two places the
 * contract allows it — {@see WebhookSetup::create()}, which is behind a button,
 * and {@see DomainFacts::$lookup}, whose answer callers cache. The cheap methods
 * are called every time a settings screen is drawn.
 *
 * ## The two transports are not the same provider to a bulk sender
 *
 * This plugin sends either over MailerSend's SMTP relay or through their Email
 * API, and that choice decides {@see capabilities()}. Over SMTP the headers
 * *are* the message and everything a plugin sets reaches the wire. Over the API
 * the message is turned into a JSON body, and MailerSend's body has no place
 * for an arbitrary header unless the account is on their Professional or
 * Enterprise plan — `list_unsubscribe` is a separate field of its own with the
 * same restriction, and this plugin's API transport does not set it.
 *
 * So a store on the API transport is told that `List-Unsubscribe` does not
 * survive, which is a sentence worth reading before forty thousand messages go
 * out without an unsubscribe button and Gmail starts filing the lot as spam.
 */
final class MailerSendProvider implements Provider
{
    /** The engine this plugin registers on `onEmailEngines`. */
    public const ENGINE = 'mailersend';

    /** The key that addresses a webhook route and a config block. */
    public const KEY = 'mailersend';

    /** The brand, spelled the way they spell it. */
    public const LABEL = 'MailerSend';

    /** This plugin's own two transports, as its config spells them. */
    public const TRANSPORT_API = 'api';
    public const TRANSPORT_SMTP = 'smtp';

    /** The `include:` an SPF record has to end up sending people to. */
    public const SPF_INCLUDE = '_spf.mailersend.net';

    /** The zone a DKIM selector CNAMEs into. Their two keys land at `ms1.` and `ms2.` under it. */
    public const DKIM_ZONE = '_domainkey.mailersend.net';

    /** What the return path CNAME at `mta.<domain>` points at. */
    public const RETURN_PATH_ZONE = 'mailersend.net';

    /** The language key for {@see instructions()}, with the English below it as the fallback. */
    public const INSTRUCTIONS_KEY = 'PLUGIN_EMAIL_MAILERSEND.PROVIDER_INSTRUCTIONS';

    private readonly MailerSendApi $api;

    /**
     * @param array<string, mixed> $config this plugin's own config
     * @param \Closure|null $save called as `($save)(array $values): void` to write
     *        keys back into that config, which is how the signing secret gets
     *        saved when the webhook is created
     * @param \Closure|null $translate called as `($translate)(string $key): ?string`,
     *        null outside Grav
     */
    public function __construct(
        private readonly array $config = [],
        ?Http $http = null,
        private readonly ?\Closure $save = null,
        private readonly ?\Closure $translate = null,
    ) {
        $this->api = new MailerSendApi($http ?? new CurlHttp());
    }

    public function engines(): array
    {
        return [self::ENGINE];
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return self::LABEL;
    }

    public function capabilities(): Capabilities
    {
        $overSmtp = $this->transport() === self::TRANSPORT_SMTP;

        return new Capabilities(
            customHeaders: $overSmtp,
            unsubscribeHeaders: $overSmtp,
            // Never, in either payload version and on either transport.
            echoesHeaders: false,
            echoNote: $overSmtp
                ? 'MailerSend\'s webhooks carry no headers and no metadata, so a header you set on a message never comes back. Events are matched by the recipient\'s address and by MailerSend\'s own message id instead.'
                : 'MailerSend\'s Email API only carries custom headers and List-Unsubscribe on their Professional and Enterprise plans, and this plugin does not set them. Switch this plugin\'s transport to SMTP if a header has to reach the wire. Either way MailerSend\'s webhooks never hand a header back.',
        );
    }

    public function reports(): ?DeliveryReports
    {
        return new MailerSendReports();
    }

    public function setup(): ?WebhookSetup
    {
        return new MailerSendSetup($this->api, $this->save);
    }

    public function domain(): DomainFacts
    {
        $token = trim((string)($this->config['api_key'] ?? ''));

        return new DomainFacts(
            spfInclude: self::SPF_INCLUDE,
            dkimZone: self::DKIM_ZONE,
            returnPathZone: self::RETURN_PATH_ZONE,
            // No key, no lookup. A closure that could only ever answer nothing
            // is worse than no closure, because a caller reads one as "this
            // provider will tell you" and the other as "ask the merchant".
            lookup: $token === ''
                ? null
                : fn (string $domain): array => $this->api->domainFacts($token, $domain),
        );
    }

    public function instructions(): string
    {
        $translated = $this->translate === null ? null : ($this->translate)(self::INSTRUCTIONS_KEY);

        if (\is_string($translated) && trim($translated) !== '' && $translated !== self::INSTRUCTIONS_KEY) {
            return $translated;
        }

        return self::instructionsInEnglish();
    }

    /**
     * The manual steps, naming the screens and the boxes.
     *
     * "Configure a webhook" is not instructions, and MailerSend calls three
     * different things webhooks depending on which part of the dashboard you
     * are standing in.
     */
    public static function instructionsInEnglish(): string
    {
        return 'In MailerSend, open Domains, click Manage beside the domain this site sends from, and go to the Webhooks tab. Add a webhook, paste the address above into the URL box, name it anything you like, and tick Delivered, Hard bounced, Soft bounced, Spam complaint, Opened and Clicked. Save it, then copy the signing secret MailerSend shows you into the Signing secret field in this plugin\'s settings — it is only shown once, and there is no way to see it again afterwards.';
    }

    /** Which of this plugin's two transports is in use. */
    private function transport(): string
    {
        $transport = strtolower(trim((string)($this->config['transport'] ?? self::TRANSPORT_API)));

        return $transport === self::TRANSPORT_SMTP ? self::TRANSPORT_SMTP : self::TRANSPORT_API;
    }
}
