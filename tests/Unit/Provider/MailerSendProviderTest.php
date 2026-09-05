<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\Provider;
use Grav\Plugin\EmailMailersend\Provider\MailerSendProvider;
use Grav\Plugin\EmailMailersend\Provider\MailerSendReports;
use Grav\Plugin\EmailMailersend\Tests\Support\FakeHttp;
use PHPUnit\Framework\TestCase;

/**
 * What this plugin answers about MailerSend when a settings screen asks.
 *
 * All of it is cheap — no network, no Grav, no config file — because every one
 * of these methods is called each time a screen is drawn, and a round trip in
 * one of them is a settings page that hangs when somebody else's API is slow.
 */
final class MailerSendProviderTest extends TestCase
{
    public function testItIsTheContractsProvider(): void
    {
        self::assertInstanceOf(Provider::class, new MailerSendProvider());
    }

    public function testItAnswersForTheEngineThisPluginRegisters(): void
    {
        $provider = new MailerSendProvider();

        self::assertSame(['mailersend'], $provider->engines());
        self::assertSame('mailersend', $provider->key());
        self::assertSame('MailerSend', $provider->label());
    }

    public function testOverSmtpTheHeadersAreTheMessage(): void
    {
        $capabilities = (new MailerSendProvider(['transport' => 'smtp']))->capabilities();

        self::assertTrue($capabilities->customHeaders);
        self::assertTrue($capabilities->unsubscribeHeaders);
        self::assertFalse($capabilities->echoesHeaders);
        self::assertStringContainsString('never comes back', $capabilities->echoNote);
    }

    /**
     * The answer worth being honest about. MailerSend's Email API carries a
     * custom header and `list_unsubscribe` on their Professional and Enterprise
     * plans only, and this plugin's API transport sets neither — so a bulk
     * sender on it goes out with no unsubscribe button, looks fine, and is
     * being filed as spam by Gmail a year later with nothing on any screen
     * saying why.
     */
    public function testOverTheApiTheyDoNot(): void
    {
        foreach ([['transport' => 'api'], [], ['transport' => 'SOMETHING ELSE']] as $config) {
            $capabilities = (new MailerSendProvider($config))->capabilities();

            self::assertFalse($capabilities->customHeaders);
            self::assertFalse($capabilities->unsubscribeHeaders);
            self::assertFalse($capabilities->echoesHeaders);
            self::assertStringContainsString('Professional and Enterprise', $capabilities->echoNote);
        }
    }

    public function testItReportsTheFiveThingsItCanReport(): void
    {
        $reports = (new MailerSendProvider())->reports();

        self::assertNotNull($reports);
        self::assertSame([
            Event::DELIVERED,
            Event::BOUNCED,
            Event::COMPLAINED,
            Event::OPENED,
            Event::CLICKED,
        ], $reports->events());
        self::assertSame(MailerSendReports::SEND_HEADER, $reports->sendHeader());
    }

    public function testEveryEventItReportsIsOneOfTheContractsWords(): void
    {
        foreach (MailerSendReports::TYPES as $theirs => [$ours, $hard]) {
            unset($hard);

            self::assertContains($ours, Event::TYPES, $theirs);
        }
    }

    public function testItCanSetItselfUp(): void
    {
        self::assertNotNull((new MailerSendProvider())->setup());
    }

    public function testTheDnsFactsAreMailerSendsOwn(): void
    {
        $facts = (new MailerSendProvider())->domain();

        self::assertSame('_spf.mailersend.net', $facts->spfInclude);
        // Their two 2048-bit keys are CNAMEs at ms1._domainkey and
        // ms2._domainkey pointing into this zone.
        self::assertSame('_domainkey.mailersend.net', $facts->dkimZone);
        // The return path CNAME lives at mta.<domain> by default.
        self::assertSame('mailersend.net', $facts->returnPathZone);
    }

    public function testWithNoApiKeyThereIsNoLookupToOffer(): void
    {
        // A closure that could only ever answer nothing is worse than none: a
        // caller reads one as "this provider will tell you" and the other as
        // "ask the merchant".
        self::assertNull((new MailerSendProvider())->domain()->lookup);
        self::assertSame([], (new MailerSendProvider())->domain()->ask('example.com'));
    }

    public function testTheLookupReadsTheSelectorsAndTheReturnPathTheAccountActuallyHas(): void
    {
        $http = (new FakeHttp())
            ->queue(200, ['data' => [['id' => 'dm1', 'name' => 'example.com']]])
            ->queue(200, ['data' => [
                'spf' => ['hostname' => 'example.com', 'type' => 'TXT', 'value' => 'v=spf1 include:_spf.mailersend.net -all'],
                'dkim_ms1' => ['hostname' => 'ms1._domainkey.example.com', 'type' => 'CNAME', 'value' => 'ms1._domainkey.mailersend.net'],
                'dkim_ms2' => ['hostname' => 'ms2._domainkey.example.com', 'type' => 'CNAME', 'value' => 'ms2._domainkey.mailersend.net'],
                'return_path' => ['hostname' => 'mta.example.com', 'type' => 'CNAME', 'value' => 'mailersend.net'],
                'custom_tracking' => ['hostname' => 'email.example.com', 'type' => 'CNAME', 'value' => 'links.mailersend.net'],
            ]]);

        $facts = (new MailerSendProvider(['api_key' => 'mssend.abc'], $http))->domain()->ask('Example.COM');

        self::assertSame(['ms1', 'ms2'], $facts['selectors']);
        self::assertSame(['mta.example.com'], $facts['return_paths']);
    }

    public function testALookupAgainstADomainMailerSendDoesNotHaveAnswersNothing(): void
    {
        $http = (new FakeHttp())->queue(200, ['data' => [['id' => 'dm1', 'name' => 'example.com']]]);

        self::assertSame([], (new MailerSendProvider(['api_key' => 'mssend.abc'], $http))->domain()->ask('other.test'));
    }

    public function testALookupThroughAnApiThatIsDownAnswersNothingRatherThanBreakingAScreen(): void
    {
        $http = (new FakeHttp())->queueFailure('Operation timed out after 15001 milliseconds');

        self::assertSame([], (new MailerSendProvider(['api_key' => 'mssend.abc'], $http))->domain()->ask('example.com'));
    }

    public function testTheInstructionsNameTheScreensAndTheBoxes(): void
    {
        $instructions = (new MailerSendProvider())->instructions();

        self::assertStringContainsString('Webhooks tab', $instructions);
        self::assertStringContainsString('signing secret', $instructions);
        // The one thing a merchant cannot recover from if nobody warns them.
        self::assertStringContainsString('only shown once', $instructions);
    }

    public function testATranslationWinsAndAnUntranslatedKeyDoesNot(): void
    {
        $translated = new MailerSendProvider([], null, null, static function (string $key): string {
            return $key === MailerSendProvider::INSTRUCTIONS_KEY ? 'Dans MailerSend, ouvrez Domaines.' : $key;
        });
        self::assertStringStartsWith('Dans MailerSend', $translated->instructions());

        // Grav answers a missing key with the key itself, which is not a
        // paragraph anybody can follow.
        $missing = new MailerSendProvider([], null, null, static fn (string $key): string => $key);
        self::assertSame(MailerSendProvider::instructionsInEnglish(), $missing->instructions());

        $blank = new MailerSendProvider([], null, null, static fn (string $key): ?string => null);
        self::assertSame(MailerSendProvider::instructionsInEnglish(), $blank->instructions());
    }

    public function testTheLanguageFileCarriesTheKeyTheProviderAsksFor(): void
    {
        $languages = (string)file_get_contents(\dirname(__DIR__, 3) . '/languages.yaml');

        self::assertStringContainsString('PLUGIN_EMAIL_MAILERSEND:', $languages);
        self::assertStringContainsString('PROVIDER_INSTRUCTIONS:', $languages);
    }
}
