<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\Event;
use Grav\Plugin\Email\Providers\WebhookRequest;
use Grav\Plugin\EmailMailersend\Provider\MailerSendReports;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MailerSend's payloads, read field by field.
 *
 * The fixtures beside this file are MailerSend's own. `v2-sent.json` is copied
 * from the payload example on `developers.mailersend.com/api/v1/account/webhooks`
 * and `v1-sent.json` from the one on `mailersend.com/whats-new/webhooks-v2`,
 * both byte for byte; `webhook-test.json` is the endpoint check from the same
 * reference page. MailerSend publishes exactly one activity example per payload
 * version and says the rest are that structure with the event's own type on it,
 * so the other fixtures are those examples with the type changed and the fields
 * an event of that kind carries filled in.
 *
 * The timestamps are checked on every one of them, because a date format nobody
 * parsed reads as zero, gets quietly stamped with the receiver's clock, and puts
 * a whole store's charts a few seconds — or a few hours — out in a way nothing
 * on any screen would show.
 */
final class MailerSendParserTest extends TestCase
{
    private MailerSendReports $reports;

    protected function setUp(): void
    {
        $this->reports = new MailerSendReports();
    }

    public function testItReadsAVersionTwoDelivery(): void
    {
        $payload = $this->parse('v2-delivered.json');

        self::assertCount(1, $payload->events);

        $event = $payload->events[0];
        self::assertSame(Event::DELIVERED, $event->type);
        self::assertNull($event->hard);
        // The display name comes off and the address is lower-cased, because a
        // store keys its suppression list on the address and one spelling has
        // to win.
        self::assertSame('jane@example.com', $event->email);
        self::assertSame('6892766ae78995a317577aa1', $event->providerId);
        self::assertSame(strtotime('2025-08-05T21:24:11.000000Z'), $event->at);
    }

    public function testItReadsAVersionTwoHardBounceWithItsReason(): void
    {
        $event = $this->parse('v2-hard-bounced.json')->events[0];

        self::assertSame(Event::BOUNCED, $event->type);
        self::assertTrue($event->hard);
        self::assertTrue($event->isHardBounce());
        self::assertSame('nobody@example.com', $event->email);
        self::assertSame('550 5.1.1 The email account that you tried to reach does not exist', $event->reason);
    }

    public function testASoftBounceIsNotPermanentAndIsReadFromTheOtherRecipientSpelling(): void
    {
        $event = $this->parse('v2-soft-bounced.json')->events[0];

        self::assertSame(Event::BOUNCED, $event->type);
        self::assertFalse($event->hard);
        self::assertFalse($event->isHardBounce());
        // MailerSend's API reference calls this field `email` and their release
        // note calls it `recipient`. This fixture uses the second spelling.
        self::assertSame('full@example.com', $event->email);
        self::assertSame("452 4.2.2 The recipient's mailbox is full", $event->reason);
    }

    public function testASpamComplaintGetsASentenceOfItsOwn(): void
    {
        $event = $this->parse('v2-spam-complaint.json')->events[0];

        self::assertSame(Event::COMPLAINED, $event->type);
        self::assertNull($event->hard);
        // `meta` is null on this one, which is a normal answer rather than a
        // sign anything went wrong.
        self::assertSame('marked as spam', $event->reason);
    }

    public function testItReadsAnOpen(): void
    {
        $event = $this->parse('v2-opened.json')->events[0];

        self::assertSame(Event::OPENED, $event->type);
        self::assertSame('reader@example.com', $event->email);
        self::assertNull($event->reason);
    }

    public function testItReadsAClickWithMailerSendsOtherDateSpelling(): void
    {
        $event = $this->parse('v2-clicked.json')->events[0];

        self::assertSame(Event::CLICKED, $event->type);
        // `2025-08-05 22:31:09` rather than the ISO 8601 spelling every other
        // fixture carries. Both turn up.
        self::assertSame(strtotime('2025-08-05 22:31:09'), $event->at);
    }

    public function testItReadsAVersionOneDelivery(): void
    {
        $event = $this->parse('v1-delivered.json')->events[0];

        self::assertSame(Event::DELIVERED, $event->type);
        self::assertSame('test@mailersend.com', $event->email);
        self::assertSame('5fc0d003f718c90162341852', $event->providerId);
        // The activity's own moment, not the envelope's, which is twelve
        // hundredths of a second later.
        self::assertSame(strtotime('2020-11-27T10:08:18.258000Z'), $event->at);
    }

    public function testItReadsAVersionOneHardBounceOutOfMorph(): void
    {
        $event = $this->parse('v1-hard-bounced.json')->events[0];

        self::assertSame(Event::BOUNCED, $event->type);
        self::assertTrue($event->hard);
        self::assertSame('nobody@example.com', $event->email);
        self::assertSame('550 5.1.1 The email account that you tried to reach does not exist', $event->reason);
    }

    public function testTheMessageIdAndTheSendIdAreNeverInvented(): void
    {
        foreach (['v1-delivered.json', 'v2-delivered.json'] as $fixture) {
            $event = $this->parse($fixture)->events[0];

            // MailerSend returns neither the Message-ID the sender set nor any
            // header it was given, in either payload version. Answering null is
            // the honest answer; putting MailerSend's own id in `messageId`
            // would be the exact confusion the contract separates the two
            // fields to avoid.
            self::assertNull($event->messageId, $fixture);
            self::assertNull($event->sendId, $fixture);
            self::assertNotNull($event->providerId, $fixture);
        }
    }

    public function testSentIsSkippedRatherThanTreatedAsADelivery(): void
    {
        foreach (['v1-sent.json', 'v2-sent.json'] as $fixture) {
            $payload = $this->parse($fixture);

            self::assertTrue($payload->isEmpty(), $fixture);
            self::assertFalse($payload->unreadable, $fixture);
            self::assertStringContainsString('waiting on the receiving server', $payload->note, $fixture);
        }
    }

    public function testTheEndpointCheckIsRecognisedAndDoesNothing(): void
    {
        $payload = $this->parse('webhook-test.json');

        self::assertTrue($payload->isEmpty());
        self::assertFalse($payload->unreadable);
        self::assertStringContainsString('checking that this address answers', $payload->note);
    }

    /**
     * Every event type MailerSend can send that this store does not act on, in
     * one go. A merchant who ticked all twenty-three boxes in the dashboard
     * gets a 200 and a log line for each, never a refusal — a 4xx here is a
     * provider retrying for three days.
     */
    public function testKnownEventsThisStoreDoesNotActOnAreSkippedWithAReason(): void
    {
        foreach (array_keys(MailerSendReports::SKIPPED) as $type) {
            $payload = $this->reports->parse($this->request(json_encode([
                'type' => $type,
                'created_at' => '2025-08-05T21:23:54.000000Z',
                'data' => ['id' => 'x'],
            ], \JSON_THROW_ON_ERROR)));

            self::assertTrue($payload->isEmpty(), $type);
            self::assertFalse($payload->unreadable, $type);
            self::assertStringContainsString($type, $payload->note, $type);
        }
    }

    public function testAnEventTypeNobodyHasSeenIsSkippedRatherThanRefused(): void
    {
        $payload = $this->reports->parse($this->request('{"type":"activity.teleported","data":{}}'));

        self::assertTrue($payload->isEmpty());
        self::assertFalse($payload->unreadable);
        self::assertStringContainsString('activity.teleported', $payload->note);
    }

    #[DataProvider('bodiesThatAreNotAPayload')]
    public function testABodyThatIsNotAPayloadIsUnreadableRatherThanAnException(string $body, string $why): void
    {
        $payload = $this->reports->parse($this->request($body));

        self::assertTrue($payload->unreadable, $why);
        self::assertTrue($payload->isEmpty(), $why);
        self::assertNotSame('', $payload->note, $why);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function bodiesThatAreNotAPayload(): array
    {
        return [
            'empty' => ['', 'an empty body'],
            'truncated json' => ['{"type":"activity.delivered","data":', 'json that stops halfway'],
            'a proxy error page' => ['<html><body>502 Bad Gateway</body></html>', 'somebody else\'s error page'],
            'json that is not an object' => ['"activity.delivered"', 'a bare string'],
            'no type at all' => ['{"data":{"email":"a@b.com"}}', 'a payload with no event type'],
            'a type but no data' => ['{"type":"activity.delivered"}', 'a known type with nothing under it'],
            'data that is not an object' => ['{"type":"activity.opened","data":"nope"}', 'data as a string'],
        ];
    }

    public function testAPayloadWithNoUsableMomentLeavesTheStampToTheReceiver(): void
    {
        $event = $this->reports->parse($this->request('{"type":"activity.opened","data":{"email":"a@b.com","created_at":"the other day"}}'))->events[0];

        self::assertSame(0, $event->at);
        self::assertSame(1700000000, $event->at(1700000000)->at);
    }

    // ------------------------------------------------------------- internals

    private function parse(string $fixture): \Grav\Plugin\Email\Providers\Payload
    {
        return $this->reports->parse($this->request($this->fixture($fixture)));
    }

    private function request(string $body): WebhookRequest
    {
        return new WebhookRequest('POST', '/_email/webhook/mailersend', [], [], $body);
    }

    private function fixture(string $name): string
    {
        $path = __DIR__ . '/payloads/' . $name;

        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }
}
