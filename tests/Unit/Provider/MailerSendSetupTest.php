<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\EmailMailersend\Provider\MailerSendApi;
use Grav\Plugin\EmailMailersend\Provider\MailerSendSetup;
use Grav\Plugin\EmailMailersend\Tests\Support\FakeHttp;
use PHPUnit\Framework\TestCase;

/**
 * The setup button, against a MailerSend that answers whatever the test says.
 *
 * Three things are worth holding here, and the failures are two of them: that a
 * merchant refused by their own API reads MailerSend's own words rather than a
 * status code, and that pressing the button twice does not leave a store with
 * two webhooks quietly doubling every figure in its reports.
 */
final class MailerSendSetupTest extends TestCase
{
    private const URL = 'https://shop.example.com/_nl/webhook/mailersend/8f3c2a';

    private const CONFIG = ['api_key' => 'mssend.abc123'];

    public function testItCreatesTheWebhookAndSavesTheSigningSecret(): void
    {
        $http = (new FakeHttp())
            ->queue(200, ['data' => [['id' => 'dm1', 'name' => 'example.com']]])
            ->queue(200, ['data' => []])
            ->queue(201, ['data' => ['id' => 'wh7', 'signing_secret' => 'ms_whsec_9911']]);

        $saved = [];
        $result = $this->button($http, $saved)->create(self::URL, [], self::CONFIG);

        self::assertTrue($result->ok);
        self::assertSame('wh7', $result->webhookId);
        self::assertStringContainsString('signing secret saved here', $result->message);
        self::assertSame(['domain_id' => 'dm1', 'signing_secret' => 'ms_whsec_9911'], $saved);

        $create = $http->call(2);
        self::assertSame('POST', $create['method']);
        self::assertSame(MailerSendApi::BASE . '/webhooks', $create['url']);
        self::assertSame('dm1', $create['body']['domain_id']);
        self::assertSame(self::URL, $create['body']['url']);
        self::assertTrue($create['body']['enabled']);
        // Version 2 is the lighter payload MailerSend recommends. The parser
        // reads version 1 too, for a webhook that was made before this button.
        self::assertSame(2, $create['body']['version']);
        self::assertSame('Bearer mssend.abc123', $create['headers']['Authorization']);
    }

    public function testItRegistersTheSixEventsBehindTheFiveWordsAndNothingElse(): void
    {
        $http = (new FakeHttp())
            ->queue(200, ['data' => [['id' => 'dm1', 'name' => 'example.com']]])
            ->queue(200, ['data' => []])
            ->queue(201, ['data' => ['id' => 'wh7', 'signing_secret' => 's']]);

        $saved = [];
        $this->button($http, $saved)->create(self::URL, [], self::CONFIG);

        self::assertSame([
            'activity.delivered',
            'activity.hard_bounced',
            'activity.soft_bounced',
            'activity.spam_complaint',
            'activity.opened',
            'activity.clicked',
        ], $http->call(2)['body']['events']);
    }

    public function testItAsksForOnlyTheEventsItWasGivenAndIgnoresOneMailerSendCannotSend(): void
    {
        $http = (new FakeHttp())
            ->queue(200, ['data' => [['id' => 'dm1', 'name' => 'example.com']]])
            ->queue(200, ['data' => []])
            ->queue(201, ['data' => ['id' => 'wh7', 'signing_secret' => 's']]);

        $saved = [];
        // `dropped` is in the contract's vocabulary and MailerSend has nothing
        // that means it. The contract says ignore it, not refuse it.
        $this->button($http, $saved)->create(self::URL, [Event::BOUNCED, Event::DROPPED], self::CONFIG);

        self::assertSame(['activity.hard_bounced', 'activity.soft_bounced'], $http->call(2)['body']['events']);
    }

    public function testPressingItTwiceUpdatesTheWebhookRatherThanMakingASecond(): void
    {
        $http = (new FakeHttp())
            ->queue(200, ['data' => [['id' => 'dm1', 'name' => 'example.com']]])
            ->queue(200, ['data' => [
                ['id' => 'wh1', 'url' => 'https://elsewhere.example.com/hook'],
                ['id' => 'wh7', 'url' => self::URL],
            ]])
            ->queue(200, ['data' => ['id' => 'wh7', 'signing_secret' => 'ms_whsec_9911']]);

        $saved = [];
        $result = $this->button($http, $saved)->create(self::URL, [], self::CONFIG);

        self::assertTrue($result->ok);
        self::assertSame('wh7', $result->webhookId);
        self::assertStringContainsString('was updated in MailerSend', $result->message);

        $update = $http->call(2);
        self::assertSame('PUT', $update['method']);
        self::assertSame(MailerSendApi::BASE . '/webhooks/wh7', $update['url']);
        // A webhook cannot be moved between domains, and sending the field on
        // an update is a 422 rather than a no-op.
        self::assertArrayNotHasKey('domain_id', $update['body']);
    }

    /**
     * A webhook registered against an older secret is pointed at the new
     * address rather than left dead beside a new one.
     */
    public function testAWebhookOnAnOlderSecretIsPointedAtTheNewAddress(): void
    {
        $http = (new FakeHttp())
            ->queue(200, ['data' => [['id' => 'dm1', 'name' => 'example.com']]])
            ->queue(200, ['data' => [
                ['id' => 'wh1', 'url' => 'https://elsewhere.example.com/hook'],
                ['id' => 'wh7', 'url' => 'https://shop.example.com/_nl/webhook/mailersend/the-old-secret'],
            ]])
            ->queue(200, ['data' => ['id' => 'wh7', 'signing_secret' => 'ms_whsec_9911']]);

        $saved = [];
        $result = $this->button($http, $saved)->create(self::URL, [], self::CONFIG);

        self::assertTrue($result->ok);
        self::assertSame('wh7', $result->webhookId);
        self::assertStringContainsString('older secret', $result->message);
        self::assertCount(3, $http->calls, 'nothing should have been created');

        $update = $http->call(2);
        self::assertSame('PUT', $update['method'], 'nothing should have been created');
        self::assertSame(MailerSendApi::BASE . '/webhooks/wh7', $update['url']);
        self::assertSame(self::URL, $update['body']['url']);
        self::assertTrue($update['body']['enabled']);
        self::assertSame(2, $update['body']['version']);
        self::assertSame([
            'activity.delivered',
            'activity.hard_bounced',
            'activity.soft_bounced',
            'activity.spam_complaint',
            'activity.opened',
            'activity.clicked',
        ], $update['body']['events']);
        self::assertArrayNotHasKey('domain_id', $update['body']);
    }

    /** A refused repointing comes back in MailerSend's own words. */
    public function testARefusedRepointingIsAPlainSentence(): void
    {
        $http = (new FakeHttp())
            ->queue(200, ['data' => [['id' => 'dm1', 'name' => 'example.com']]])
            ->queue(200, ['data' => [
                ['id' => 'wh7', 'url' => 'https://shop.example.com/_nl/webhook/mailersend/the-old-secret'],
            ]])
            ->queue(422, ['message' => 'The url must be a valid URL.']);

        $saved = [];
        $result = $this->button($http, $saved)->create(self::URL, [], self::CONFIG);

        self::assertFalse($result->ok);
        self::assertStringContainsString('must be a valid URL', $result->message);
        self::assertSame([], $saved);
    }

    public function testAnUpdateWithNoSecretBackSaysWhatToDoAboutIt(): void
    {
        $http = (new FakeHttp())
            ->queue(200, ['data' => [['id' => 'dm1', 'name' => 'example.com']]])
            ->queue(200, ['data' => [['id' => 'wh7', 'url' => self::URL]]])
            ->queue(200, ['data' => ['id' => 'wh7']]);

        $saved = [];
        $result = $this->button($http, $saved)->create(self::URL, [], self::CONFIG);

        self::assertTrue($result->ok);
        self::assertStringContainsString('delete the webhook in MailerSend and press this again', $result->message);
        self::assertSame(['domain_id' => 'dm1'], $saved);
    }

    public function testAKeyWithoutTheWebhooksPermissionIsRefusedInMailerSendsOwnWords(): void
    {
        $http = (new FakeHttp())
            ->queue(200, ['data' => [['id' => 'dm1', 'name' => 'example.com']]])
            ->queue(403, ['message' => 'The custom API token you\'re using doesn\'t have the required permissions.']);

        $saved = [];
        $result = $this->button($http, $saved)->create(self::URL, [], self::CONFIG);

        self::assertFalse($result->ok);
        self::assertStringContainsString('doesn\'t have the required permissions', $result->message);
        self::assertSame([], $saved);
    }

    public function testAnExpiredTokenSaysTheThingNobodyKnows(): void
    {
        $http = (new FakeHttp())->queue(401, ['message' => 'Unauthenticated.']);

        $saved = [];
        $result = $this->button($http, $saved)->create(self::URL, [], self::CONFIG);

        self::assertFalse($result->ok);
        // A token created without an expiry date lasts 24 hours, which is the
        // single most common reason one of these stops working.
        self::assertStringContainsString('24 hours', $result->message);
    }

    public function testAValidationErrorComesThroughFieldByField(): void
    {
        $http = (new FakeHttp())
            ->queue(200, ['data' => [['id' => 'dm1', 'name' => 'example.com']]])
            ->queue(200, ['data' => []])
            ->queue(422, [
                'message' => 'The given data was invalid.',
                'errors' => ['url' => ['The url must not be greater than 191 characters.']],
            ]);

        $saved = [];
        $result = $this->button($http, $saved)->create(self::URL, [], self::CONFIG);

        self::assertFalse($result->ok);
        self::assertStringContainsString('must not be greater than 191 characters', $result->message);
    }

    public function testAnUnreachableApiSaysSoRatherThanThrowing(): void
    {
        $http = (new FakeHttp())->queueFailure('Could not resolve host: api.mailersend.com');

        $saved = [];
        $result = $this->button($http, $saved)->create(self::URL, [], self::CONFIG);

        self::assertFalse($result->ok);
        self::assertStringContainsString('MailerSend could not be reached', $result->message);
        self::assertStringContainsString('Could not resolve host', $result->message);
    }

    public function testWithNoApiKeyItSaysWhichPermissionsTheKeyWillNeed(): void
    {
        $http = new FakeHttp();
        $saved = [];

        $result = $this->button($http, $saved)->create(self::URL, [], []);

        self::assertFalse($result->ok);
        self::assertStringContainsString('no MailerSend API key', $result->message);
        self::assertStringContainsString('Webhooks set to Full access', $result->message);
        self::assertSame([], $http->calls);
    }

    public function testItWillNotRegisterAnAddressMailerSendCannotPostTo(): void
    {
        $http = new FakeHttp();
        $saved = [];

        $result = $this->button($http, $saved)->create('http://shop.example.com/hook', [], self::CONFIG);

        self::assertFalse($result->ok);
        self::assertStringContainsString('only post to an https address', $result->message);
        self::assertSame([], $http->calls);
    }

    public function testWithSeveralDomainsAndNoneNamedItSaysWhichOnesThereAre(): void
    {
        $http = (new FakeHttp())->queue(200, ['data' => [
            ['id' => 'dm1', 'name' => 'example.com'],
            ['id' => 'dm2', 'name' => 'shop.example.com'],
        ]]);

        $saved = [];
        $result = $this->button($http, $saved)->create(self::URL, [], self::CONFIG);

        self::assertFalse($result->ok);
        self::assertStringContainsString('example.com, shop.example.com', $result->message);
        self::assertStringContainsString('Sending domain field', $result->message);
    }

    public function testANamedDomainPicksTheRightOne(): void
    {
        $http = (new FakeHttp())
            ->queue(200, ['data' => [
                ['id' => 'dm1', 'name' => 'example.com'],
                ['id' => 'dm2', 'name' => 'Shop.Example.com'],
            ]])
            ->queue(200, ['data' => []])
            ->queue(201, ['data' => ['id' => 'wh7', 'signing_secret' => 's']]);

        $saved = [];
        $result = $this->button($http, $saved)->create(self::URL, [], self::CONFIG + ['domain' => 'SHOP.example.com']);

        self::assertTrue($result->ok);
        self::assertSame('dm2', $http->call(2)['body']['domain_id']);
    }

    public function testANamedDomainThatIsNotThereNamesTheOnesThatAre(): void
    {
        $http = (new FakeHttp())->queue(200, ['data' => [['id' => 'dm1', 'name' => 'example.com']]]);

        $saved = [];
        $result = $this->button($http, $saved)->create(self::URL, [], self::CONFIG + ['domain' => 'other.test']);

        self::assertFalse($result->ok);
        self::assertStringContainsString('no verified domain called other.test', $result->message);
        self::assertStringContainsString('It has example.com.', $result->message);
    }

    public function testAnAccountWithNoVerifiedDomainSaysThat(): void
    {
        $http = (new FakeHttp())->queue(200, ['data' => []]);

        $saved = [];
        $result = $this->button($http, $saved)->create(self::URL, [], self::CONFIG);

        self::assertFalse($result->ok);
        self::assertStringContainsString('no verified sending domain yet', $result->message);
    }

    public function testASavedDomainIdSkipsTheLookupAltogether(): void
    {
        $http = (new FakeHttp())
            ->queue(200, ['data' => []])
            ->queue(201, ['data' => ['id' => 'wh7', 'signing_secret' => 's']]);

        $saved = [];
        $result = $this->button($http, $saved)->create(self::URL, [], self::CONFIG + ['domain_id' => 'dm9']);

        self::assertTrue($result->ok);
        self::assertStringContainsString('/webhooks?domain_id=dm9', $http->call(0)['url']);
        self::assertCount(2, $http->calls);
    }

    public function testAWriterThatThrowsCostsNothing(): void
    {
        $http = (new FakeHttp())
            ->queue(200, ['data' => [['id' => 'dm1', 'name' => 'example.com']]])
            ->queue(200, ['data' => []])
            ->queue(201, ['data' => ['id' => 'wh7', 'signing_secret' => 's']]);

        $setup = new MailerSendSetup(new MailerSendApi($http), static function (array $values): void {
            unset($values);

            throw new \RuntimeException('the settings file is read only');
        });

        $result = $setup->create(self::URL, [], self::CONFIG);

        // The webhook exists in MailerSend either way, and a settings file that
        // cannot be written is a secret to paste by hand rather than an
        // exception out of a button press.
        self::assertTrue($result->ok);
    }

    public function testThePermissionSentenceNamesBothOfThem(): void
    {
        $needed = (new MailerSendSetup(new MailerSendApi(new FakeHttp())))->permissionsNeeded();

        self::assertStringContainsString('Webhooks set to Full access', $needed);
        self::assertStringContainsString('Domains set to Read only', $needed);
    }

    // ------------------------------------------------------------- internals

    /** @param array<string, mixed> $saved */
    private function button(FakeHttp $http, array &$saved): MailerSendSetup
    {
        return new MailerSendSetup(
            new MailerSendApi($http),
            static function (array $values) use (&$saved): void {
                $saved = array_replace($saved, $values);
            },
        );
    }
}
