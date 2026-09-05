<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Tests\Unit\Transport;

use Grav\Plugin\EmailMailersend\Transport\MailersendApiTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * The SMTP transport hands MailerSend the whole message, so anything set on it
 * arrives. The API transport rebuilds the message as JSON, so a field only
 * arrives if getPayload() puts it there - and that is what these tests hold in
 * place.
 *
 * Everything asserted here is from MailerSend's own `POST /v1/email` reference,
 * read 2026-09-05.
 */
final class MailersendApiTransportPayloadTest extends TestCase
{
    public function testAMessageWithEveryFieldOnItBecomesTheDocumentedPayload(): void
    {
        $sendAt = time() + 3600;

        $email = (new Email())
            ->from(new Address('sender@example.com', 'Kahuna Store'))
            ->to(new Address('buyer@example.com', 'A Buyer'))
            ->cc(new Address('copy@example.com', 'Copy Desk'))
            ->bcc('blind@example.com')
            ->replyTo(new Address('replies@example.com', 'Replies'), 'second@example.com')
            ->subject('Your order')
            ->text('Plain body')
            ->html('<p>HTML body</p>')
            ->date(new \DateTimeImmutable('@' . $sendAt));

        $email->attach('report bytes', 'report.csv', 'text/csv');

        $headers = $email->getHeaders();
        $headers->add(new TagHeader('receipts'));
        $headers->add(new MetadataHeader('send', 'abc123'));
        $headers->addTextHeader('In-Reply-To', '<parent@example.com>');
        $headers->addTextHeader('References', '<grandparent@example.com> <parent@example.com>');
        $headers->addTextHeader('List-Unsubscribe', '<https://example.com/u?t=abc>, <mailto:unsubscribe@example.com>');
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        $headers->addTextHeader('X-Campaign', 'september');

        $payload = $this->payload($email);

        self::assertSame(['email' => 'sender@example.com', 'name' => 'Kahuna Store'], $payload['from']);
        self::assertSame([['email' => 'buyer@example.com', 'name' => 'A Buyer']], $payload['to']);
        self::assertSame([['email' => 'copy@example.com', 'name' => 'Copy Desk']], $payload['cc']);
        self::assertSame([['email' => 'blind@example.com']], $payload['bcc']);
        self::assertSame('Your order', $payload['subject']);
        self::assertSame('Plain body', $payload['text']);
        self::assertSame('<p>HTML body</p>', $payload['html']);

        // MailerSend's reply_to is one object, not a list, so only the first
        // address travels.
        self::assertSame(['email' => 'replies@example.com', 'name' => 'Replies'], $payload['reply_to']);

        self::assertSame(['receipts'], $payload['tags']);
        self::assertSame('<https://example.com/u?t=abc>', $payload['list_unsubscribe']);
        self::assertSame('<parent@example.com>', $payload['in_reply_to']);
        self::assertSame(['grandparent@example.com', 'parent@example.com'], $payload['references']);
        self::assertSame($sendAt, $payload['send_at']);

        self::assertSame([
            ['name' => 'X-Metadata-send', 'value' => 'abc123'],
            ['name' => 'X-Campaign', 'value' => 'september'],
        ], $payload['headers']);

        self::assertSame([[
            'content' => base64_encode('report bytes'),
            'filename' => 'report.csv',
            'disposition' => 'attachment',
        ]], $payload['attachments']);
    }

    /**
     * The `headers` list is for headers a caller added by hand. Everything the
     * payload already expresses as a field of its own - and everything
     * MailerSend writes itself - has to stay out of it, or the delivered mail
     * carries two of each.
     */
    public function testNoEnvelopeOrMimeHeaderLeaksIntoTheHeadersList(): void
    {
        $email = (new Email())
            ->from('sender@example.com')
            ->to('buyer@example.com')
            ->cc('copy@example.com')
            ->bcc('blind@example.com')
            ->replyTo('replies@example.com')
            ->returnPath('bounces@example.com')
            ->subject('Your order')
            ->text('Plain body');

        $headers = $email->getHeaders();
        $headers->addDateHeader('Date', new \DateTimeImmutable('2026-09-05 09:00:00'));
        $headers->addIdHeader('Message-ID', 'minted@example.com');
        $headers->addTextHeader('MIME-Version', '1.0');
        $headers->addTextHeader('Content-Type', 'text/plain');
        $headers->addTextHeader('Content-Transfer-Encoding', 'quoted-printable');
        $headers->addTextHeader('Received', 'from somewhere');
        $headers->addTextHeader('In-Reply-To', '<parent@example.com>');
        $headers->addTextHeader('References', '<parent@example.com>');
        $headers->addTextHeader('List-Unsubscribe', '<https://example.com/u>');
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        $headers->addTextHeader('X-Campaign', 'september');

        self::assertSame([['name' => 'X-Campaign', 'value' => 'september']], $this->payload($email)['headers']);
    }

    /**
     * A store on MailerSend's free or Hobby plan must never be sent a field
     * their plan refuses, and the way to promise that is to send `headers` only
     * when there is a header to put in it.
     */
    public function testAMessageWithNoCustomHeadersSendsNoHeadersField(): void
    {
        $payload = $this->payload($this->plainMessage());

        self::assertArrayNotHasKey('headers', $payload);
        self::assertArrayNotHasKey('list_unsubscribe', $payload);
        self::assertArrayNotHasKey('in_reply_to', $payload);
        self::assertArrayNotHasKey('references', $payload);
        self::assertArrayNotHasKey('tags', $payload);
        self::assertArrayNotHasKey('attachments', $payload);
        self::assertArrayNotHasKey('send_at', $payload);
    }

    /**
     * MailerSend takes one value, and sets List-Unsubscribe-Post itself when
     * that value is a URL - which is why the https half of the header is the
     * half that travels.
     */
    public function testTheUrlHalfOfTheUnsubscribeHeaderIsTheHalfThatTravels(): void
    {
        $email = $this->plainMessage();
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<mailto:unsubscribe@example.com>, <https://example.com/u?t=abc>');

        self::assertSame('<https://example.com/u?t=abc>', $this->payload($email)['list_unsubscribe']);
    }

    public function testAMailtoOnlyUnsubscribeHeaderTravelsAsTheMailto(): void
    {
        $email = $this->plainMessage();
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<mailto:unsubscribe@example.com>');

        self::assertSame('<mailto:unsubscribe@example.com>', $this->payload($email)['list_unsubscribe']);
    }

    public function testAnUnbracketedUnsubscribeHeaderIsStillRead(): void
    {
        $email = $this->plainMessage();
        $email->getHeaders()->addTextHeader('List-Unsubscribe', 'https://example.com/u');

        self::assertSame('<https://example.com/u>', $this->payload($email)['list_unsubscribe']);
    }

    /**
     * MailerSend caps the field at 990 characters. Leaving it off is better
     * than having the whole send refused over an unsubscribe link.
     */
    public function testAnUnsubscribeValueTooLongForMailersendIsLeftOff(): void
    {
        $email = $this->plainMessage();
        $email->getHeaders()->addTextHeader('List-Unsubscribe', '<https://example.com/' . str_repeat('x', 1000) . '>');

        self::assertArrayNotHasKey('list_unsubscribe', $this->payload($email));
    }

    public function testTagsAreTruncatedToMailersendsLimitAndCappedAtFive(): void
    {
        $email = $this->plainMessage();
        $headers = $email->getHeaders();

        $headers->add(new TagHeader(str_repeat('a', 200)));
        $headers->add(new TagHeader('two'));
        $headers->add(new TagHeader('three'));
        $headers->add(new TagHeader('four'));
        $headers->add(new TagHeader('five'));

        $tags = $this->payload($email)['tags'];

        self::assertCount(5, $tags);
        self::assertSame(191, mb_strlen($tags[0]));
        self::assertSame(['two', 'three', 'four', 'five'], array_slice($tags, 1));
    }

    public function testASixthTagIsRefusedRatherThanQuietlyDropped(): void
    {
        $email = $this->plainMessage();

        foreach (['one', 'two', 'three', 'four', 'five', 'six'] as $tag) {
            $email->getHeaders()->add(new TagHeader($tag));
        }

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('at most 5 tags');

        $this->payload($email);
    }

    public function testAnInlineAttachmentCarriesTheIdThatTheHtmlBodyReferences(): void
    {
        $email = $this->plainMessage();
        $email->html('<img src="cid:logo.png">');
        $email->embed('png bytes', 'logo.png', 'image/png');

        self::assertSame([[
            'content' => base64_encode('png bytes'),
            'filename' => 'logo.png',
            'disposition' => 'inline',
            'id' => 'logo.png',
        ]], $this->payload($email)['attachments']);
    }

    public function testANameCarryingACommaOrSemicolonIsCleanedBecauseMailersendRefusesOne(): void
    {
        $email = (new Email())
            ->from(new Address('sender@example.com', 'Store, The'))
            ->to(new Address('buyer@example.com', 'Buyer; Esq'))
            ->subject('Your order')
            ->text('Plain body');

        $payload = $this->payload($email);

        self::assertSame(['email' => 'sender@example.com', 'name' => 'Store  The'], $payload['from']);
        self::assertSame([['email' => 'buyer@example.com', 'name' => 'Buyer  Esq']], $payload['to']);
    }

    public function testADateInThePastIsNotAScheduledSend(): void
    {
        $email = $this->plainMessage()->date(new \DateTimeImmutable('@' . (time() - 60)));

        self::assertArrayNotHasKey('send_at', $this->payload($email));
    }

    /**
     * MailerSend will not hold a message for more than 72 hours, so a date
     * beyond that goes out now rather than earning a 422.
     */
    public function testADateBeyondMailersendsSeventyTwoHoursIsNotSent(): void
    {
        $email = $this->plainMessage()->date(new \DateTimeImmutable('@' . (time() + (73 * 3600))));

        self::assertArrayNotHasKey('send_at', $this->payload($email));
    }

    private function plainMessage(): Email
    {
        return (new Email())
            ->from('sender@example.com')
            ->to('buyer@example.com')
            ->subject('Your order')
            ->text('Plain body');
    }

    /**
     * getPayload() is protected, so it is reached through a subclass rather
     * than by making a real request.
     *
     * @return array<string, mixed>
     */
    private function payload(Email $email): array
    {
        $envelope = Envelope::create($email);

        return (new class ('api-test-key', new \Symfony\Component\HttpClient\MockHttpClient()) extends MailersendApiTransport {
            /**
             * @return array<string, mixed>
             */
            public function payloadFor(Email $email, Envelope $envelope): array
            {
                return $this->getPayload($email, $envelope);
            }
        })->payloadFor($email, $envelope);
    }
}
