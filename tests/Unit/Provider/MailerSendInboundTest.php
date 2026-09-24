<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\Inbound\InboundCapable;
use Grav\Plugin\Email\Providers\Inbound\InboundGateway;
use Grav\Plugin\Email\Providers\Inbound\InboundMessage;
use Grav\Plugin\Email\Providers\Inbound\InboundReceiver;
use Grav\Plugin\Email\Providers\Inbound\InboundReference;
use Grav\Plugin\Email\Providers\Inbound\InboundRequest;
use Grav\Plugin\Email\Providers\ProviderRegistry;
use Grav\Plugin\EmailMailersend\Provider\MailerSendInbound;
use Grav\Plugin\EmailMailersend\Provider\MailerSendInboundProvider;
use Grav\Plugin\EmailMailersend\Provider\MailerSendProvider;
use Grav\Plugin\EmailMailersend\Provider\MailerSendReports;
use PHPUnit\Framework\TestCase;

/**
 * Mail received through a MailerSend inbound route: the signature, the
 * endpoint check a route is saved with, and the message built from `raw` or
 * from the fields, from MailerSend's documented payload.
 */
final class MailerSendInboundTest extends TestCase
{
    private const SECRET = 'jYhafQeTihgw0qWclkUA7cbqTG3Zfh2j';

    // ------------------------------------------------------------ discovery

    public function testTheInboundProviderIsFoundByTheGatewayAndThePlainOneIsNot(): void
    {
        self::assertNotInstanceOf(InboundCapable::class, new MailerSendProvider());
        self::assertInstanceOf(InboundCapable::class, new MailerSendInboundProvider());

        $registry = new ProviderRegistry();
        $registry->add(new MailerSendInboundProvider());
        $receiver = (new InboundGateway(null, $registry))->receiver('mailersend');

        self::assertInstanceOf(MailerSendInbound::class, $receiver);
        self::assertInstanceOf(InboundReceiver::class, $receiver);
        self::assertSame('MailerSend', $receiver->label());
        self::assertSame(['inbound_signing_secret'], $receiver->verificationKeys());
        self::assertGreaterThanOrEqual(50 * 1024 * 1024, $receiver->maxBytes());
    }

    /**
     * Beside an Email plugin with no inbound types at all, in a fresh process:
     * the plain provider loads and answers, and the plugin's check picks it.
     */
    public function testThePlainProviderLoadsWhereTheEmailPluginHasNoInbound(): void
    {
        $emailRoot = \dirname((string)(new \ReflectionClass(InboundGateway::class))->getFileName(), 4);
        $root = \dirname(__DIR__, 3);
        $script = sprintf(<<<'PHP'
            spl_autoload_register(static function (string $class): void {
                foreach (['Grav\\Plugin\\Email\\Providers\\' => %s, 'Grav\\Plugin\\EmailMailersend\\' => %s] as $prefix => $dir) {
                    if (str_starts_with($class, $prefix) && !str_starts_with($class, 'Grav\\Plugin\\Email\\Providers\\Inbound\\')) {
                        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                        if (is_file($file)) { require $file; }
                    }
                }
            });
            $class = interface_exists(Grav\Plugin\Email\Providers\Inbound\InboundCapable::class)
                ? Grav\Plugin\EmailMailersend\Provider\MailerSendInboundProvider::class
                : Grav\Plugin\EmailMailersend\Provider\MailerSendProvider::class;
            $provider = new $class(['api_key' => 'mlsn.x']);
            $provider->capabilities();
            $provider->instructions();
            echo get_class($provider);
            PHP,
            var_export($emailRoot . '/classes/Providers/', true),
            var_export($root . '/classes/', true)
        );

        $output = [];
        exec(escapeshellarg(\PHP_BINARY) . ' -r ' . escapeshellarg($script) . ' 2>&1', $output, $code);

        self::assertSame(0, $code, implode("\n", $output));
        self::assertSame(MailerSendProvider::class, implode("\n", $output));
    }

    public function testInstructionsNameTheRouteTheAddressAndTheSecret(): void
    {
        $text = (new MailerSendInbound())->instructions('https://example.com/_helpdesk/inbound/mailersend/abc');

        self::assertStringContainsString('https://example.com/_helpdesk/inbound/mailersend/abc', $text);
        self::assertStringContainsString('inbound route', $text);
        self::assertStringContainsString('MX record', $text);
        self::assertStringContainsString('Inbound signing secret', $text);
    }

    // ---------------------------------------------------------------- verify

    public function testASignedPostIsVerified(): void
    {
        $body = self::fixture('inbound-with-raw.json');
        $verdict = (new MailerSendInbound())->verify(self::request($body, self::SECRET), ['inbound_signing_secret' => self::SECRET]);

        self::assertTrue($verdict->ok, $verdict->reason);
        self::assertTrue($verdict->signed);
    }

    public function testABadSignatureIsRefused(): void
    {
        $body = self::fixture('inbound-with-raw.json');
        $verdict = (new MailerSendInbound())->verify(self::request($body, 'somebody-else'), ['inbound_signing_secret' => self::SECRET]);

        self::assertFalse($verdict->ok);
        self::assertStringContainsString('did not match', $verdict->reason);
    }

    public function testATamperedBodyIsRefused(): void
    {
        $body = self::fixture('inbound-with-raw.json');
        $request = new InboundRequest(
            headers: ['signature' => hash_hmac('sha256', $body, self::SECRET)],
            body: str_replace('Still on fire', 'Now it is out', $body),
        );

        self::assertFalse((new MailerSendInbound())->verify($request, ['inbound_signing_secret' => self::SECRET])->ok);
    }

    public function testAMissingSignatureIsRefused(): void
    {
        $request = new InboundRequest(body: self::fixture('inbound-with-raw.json'));

        self::assertFalse((new MailerSendInbound())->verify($request, ['inbound_signing_secret' => self::SECRET])->ok);
    }

    /**
     * The delivery webhook's signing secret is a different webhook's secret
     * and is not read here.
     */
    public function testTheDeliveryWebhookSecretIsNotTheInboundOne(): void
    {
        $body = self::fixture('inbound-with-raw.json');
        $verdict = (new MailerSendInbound())->verify(self::request($body, self::SECRET), ['signing_secret' => self::SECRET]);

        self::assertFalse($verdict->ok);
        self::assertStringContainsString('inbound signing secret', $verdict->reason);
    }

    /**
     * The ping a route is saved with, signed with MailerSend's published test
     * secret, passes even before the real secret is known, and is nothing.
     */
    public function testTheEndpointCheckPassesAndIsNothing(): void
    {
        $body = self::fixture('webhook-test.json');
        $request = self::request($body, MailerSendReports::TEST_SECRET);
        $receiver = new MailerSendInbound();

        self::assertTrue($receiver->verify($request, [])->ok);
        self::assertTrue($receiver->verify($request, ['inbound_signing_secret' => self::SECRET])->ok);

        $payload = $receiver->parse($request, []);
        self::assertTrue($payload->isEmpty());
        self::assertFalse($payload->unreadable);
    }

    /** A real message signed with the public test secret is not a pass. */
    public function testAMessageSignedWithThePublicTestSecretIsRefused(): void
    {
        $request = self::request(self::fixture('inbound-with-raw.json'), MailerSendReports::TEST_SECRET);

        self::assertFalse((new MailerSendInbound())->verify($request, ['inbound_signing_secret' => self::SECRET])->ok);
        self::assertFalse((new MailerSendInbound())->verify($request, [])->ok);
    }

    // ----------------------------------------------------------------- parse

    public function testWithRawTheMessageIsBuiltFromTheMime(): void
    {
        $message = self::one('inbound-with-raw.json');
        $data = json_decode(self::fixture('inbound-with-raw.json'), true)['data'];

        self::assertSame($data['raw'], $message->raw);
        self::assertSame('mailersend', $message->receiver);
        self::assertSame('66f1a2b3c4d5e6f708192a3b', $message->providerId);
        self::assertSame('cab1234@mail.example.net', $message->messageId);
        self::assertSame('hd.1042.7@support.example.com', $message->inReplyTo);
        self::assertSame(['hd.1042.1@support.example.com', 'hd.1042.7@support.example.com'], $message->references);
        self::assertSame('Re: [#1042] Drucker brännt', $message->subject);
        self::assertSame('casey@example.net', $message->from->email);
        self::assertSame('Casey Customer', $message->from->name);
        self::assertSame('Still on fire.', trim((string)$message->text));

        // The envelope from rcptTo keeps the plus address the visible To lost.
        self::assertSame(['support+t8f2k@support.example.com'], $message->envelopeTo);
        self::assertSame('support@support.example.com', $message->to[0]->email);
        self::assertSame('bounces+abc@example.net', $message->envelopeFrom);

        self::assertCount(1, $message->attachments);
        self::assertSame('fire.png', $message->attachments[0]->filename);
        self::assertSame("\x89PNG\r\n\x1a\nfire", $message->attachments[0]->bytes());

        // spf_check and dkim_check laid over the message's own results, which
        // still give DMARC.
        self::assertSame(['spf' => 'softfail', 'dkim' => 'pass', 'dmarc' => 'pass'], $message->auth);
    }

    /**
     * MailerSend's own documented sample, whose `raw` is a placeholder rather
     * than a message: built from the fields.
     */
    public function testWithoutRawTheMessageIsBuiltFromTheFields(): void
    {
        $message = self::one('inbound-without-raw.json');

        self::assertNull($message->raw);
        self::assertSame('mailersend', $message->receiver);
        self::assertSame('6719d6e014059a29f74b16bf', $message->providerId);
        self::assertSame('message-id', $message->messageId);
        self::assertSame('sender@example.com', $message->from->email);
        self::assertSame('Test User', $message->from->name);
        self::assertSame('s1krr0oeqilgmcbnohxg@inbound.mailersend.net', $message->to[0]->email);
        self::assertSame(['s1krr0oeqilgmcbnohxg@inbound.mailersend.net'], $message->envelopeTo);
        self::assertSame('sender@example.com', $message->envelopeFrom);
        self::assertSame('Test Inbound Routing', $message->subject);
        self::assertSame(strtotime('Thu, 24 Oct 2024 05:10:42 +0000'), $message->date);
        self::assertSame("Hi,\r\nTesting inbound routing payload sample.\r\n", $message->text);
        self::assertStringContainsString('Testing inbound routing payload sample.', (string)$message->html);
        self::assertSame('multipart/alternative', $message->contentType);
        self::assertSame('Test Inbound Routing', $message->header('subject'));
        self::assertSame([], $message->attachments);
        self::assertSame(['spf' => 'pass', 'dkim' => 'none'], $message->auth);
    }

    public function testAttachmentsInTheFieldsAreDecoded(): void
    {
        $message = self::one('inbound-attachments.json');

        self::assertNull($message->raw);
        self::assertSame('invoice-1@example.com', $message->messageId);
        self::assertSame('hd.1042.7@support.example.com', $message->inReplyTo);
        self::assertSame('multipart/mixed', $message->contentType);
        self::assertCount(3, $message->attachments, 'the entry with no content is left out');

        [$pdf, $logo, $sneaky] = $message->attachments;
        self::assertSame('invoice.pdf', $pdf->filename);
        self::assertSame('application/pdf', $pdf->contentType);
        self::assertSame("%PDF-1.7\n", $pdf->bytes());
        self::assertSame(9, $pdf->size);
        self::assertFalse($pdf->inline);

        self::assertSame('logo.png', $logo->filename);
        self::assertSame('logo01', $logo->contentId);
        self::assertTrue($logo->inline, 'a content id with no disposition is an inline part');

        self::assertSame('passwd', $sneaky->filename, 'directories are taken off a sender\'s filename');
    }

    public function testAnotherEventIsNothing(): void
    {
        $body = (string)file_get_contents(__DIR__ . '/payloads/v2-delivered.json');
        $payload = (new MailerSendInbound())->parse(self::request($body, self::SECRET), []);

        self::assertTrue($payload->isEmpty());
        self::assertFalse($payload->unreadable);
        self::assertStringContainsString('activity.delivered', $payload->note);
    }

    public function testRubbishIsUnreadableAndNeverThrown(): void
    {
        foreach (['', 'not json', '[1,2]', '{"type":"inbound.message"}', '{"type":"inbound.message","data":"x"}'] as $body) {
            $payload = (new MailerSendInbound())->parse(new InboundRequest(body: $body), []);

            self::assertTrue($payload->unreadable, 'unreadable: ' . $body);
        }
    }

    public function testTheGatewayAcceptsASignedPostAndRefusesAForgedOne(): void
    {
        $body = self::fixture('inbound-with-raw.json');
        $registry = new ProviderRegistry();
        $registry->add(new MailerSendInboundProvider());
        $gateway = new InboundGateway(null, $registry);
        $config = ['inbound_signing_secret' => self::SECRET];

        self::assertSame(401, $gateway->receive('mailersend', self::request($body, 'forged'), $config)->status);

        $result = $gateway->receive('mailersend', self::request($body, self::SECRET), $config);
        self::assertSame(200, $result->status);
        self::assertCount(1, $result->messages());
    }

    public function testFetchIsNeverNeeded(): void
    {
        $this->expectException(\LogicException::class);

        (new MailerSendInbound())->fetch(new InboundReference('mailersend', 'x'), []);
    }

    // --------------------------------------------------------------- helpers

    private static function one(string $name): InboundMessage
    {
        $payload = (new MailerSendInbound())->parse(self::request(self::fixture($name), self::SECRET), []);

        self::assertFalse($payload->unreadable, $payload->note);
        self::assertCount(1, $payload->items);
        self::assertInstanceOf(InboundMessage::class, $payload->items[0]);

        return $payload->items[0];
    }

    private static function fixture(string $name): string
    {
        return (string)file_get_contents(__DIR__ . '/payloads/' . $name);
    }

    private static function request(string $body, string $secret): InboundRequest
    {
        return new InboundRequest(
            headers: ['content-type' => 'application/json', 'signature' => hash_hmac('sha256', $body, $secret)],
            body: $body,
        );
    }
}
