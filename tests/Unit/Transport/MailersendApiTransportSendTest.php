<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Tests\Unit\Transport;

use Grav\Plugin\EmailMailersend\Transport\MailersendApiTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * What the transport does with MailerSend's answer.
 *
 * A send MailerSend accepted is `202` with an empty body and the message id in
 * an `x-message-id` response header. Anything else is a refusal, and the
 * merchant reading the Grav log has to be able to tell why from one line.
 */
final class MailersendApiTransportSendTest extends TestCase
{
    public function testTheRequestGoesToMailersendsEmailEndpointWithTheTokenOnIt(): void
    {
        $seen = null;

        $transport = $this->transport(
            static function (string $method, string $url, array $options) use (&$seen): ResponseInterface {
                $seen = ['method' => $method, 'url' => $url, 'options' => $options];

                return new MockResponse('', ['http_code' => 202]);
            }
        );

        $transport->send($this->message());

        self::assertSame('POST', $seen['method']);
        self::assertSame('https://api.mailersend.com/v1/email', $seen['url']);
        self::assertContains('Authorization: Bearer api-test-key', $seen['options']['headers']);
        self::assertContains('Accept: application/json', $seen['options']['headers']);

        $body = json_decode($seen['options']['body'], true);

        self::assertSame('Your order', $body['subject']);
        self::assertSame([['email' => 'buyer@example.com']], $body['to']);
    }

    public function testMailersendsOwnMessageIdIsRecordedOnTheSentMessage(): void
    {
        $transport = $this->transport(static function (): ResponseInterface {
            return new MockResponse('', [
                'http_code' => 202,
                'response_headers' => ['x-message-id' => '62a5b1c2d3e4f5a6b7c8d9e0'],
            ]);
        });

        $sent = $transport->send($this->message());

        self::assertNotNull($sent);
        self::assertSame('62a5b1c2d3e4f5a6b7c8d9e0', $sent->getMessageId());
    }

    /**
     * An answer with no `x-message-id` on it must not cost the store the send.
     * The id stays the one Symfony minted for the message, which is what a
     * transport that could not report one would have left there anyway - the
     * package this transport replaces read that header without checking and
     * would have failed on an undefined index instead.
     */
    public function testAnAcceptedSendWithNoMessageIdHeaderIsStillASend(): void
    {
        $transport = $this->transport(static function (): ResponseInterface {
            return new MockResponse('', ['http_code' => 202]);
        });

        $sent = $transport->send($this->message());

        self::assertNotNull($sent);
        self::assertStringEndsWith('@example.com', $sent->getMessageId());
    }

    public function testARefusalNamesMailersendsOwnMessageAndTheFirstFieldError(): void
    {
        $transport = $this->transport(static function (): ResponseInterface {
            return new MockResponse(
                json_encode([
                    'message' => 'The given data was invalid.',
                    'errors' => ['to.0.email' => ['The to.0.email must be a valid email address.']],
                ]),
                ['http_code' => 422, 'response_headers' => ['content-type' => 'application/json']]
            );
        });

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Unable to send an email via MailerSend: The given data was invalid. (to.0.email: The to.0.email must be a valid email address.) (code 422).');

        $transport->send($this->message());
    }

    /**
     * `headers` and `list_unsubscribe` are Professional and Enterprise only. A
     * 422 naming one of them reads as a bug in the site until somebody knows
     * that, so the exception says it.
     */
    public function testARefusalOverAPaidPlanFieldSaysWhichPlanItNeeds(): void
    {
        $transport = $this->transport(static function (): ResponseInterface {
            return new MockResponse(
                json_encode([
                    'message' => 'The given data was invalid.',
                    'errors' => ['headers' => ['Custom headers are not available on your plan.']],
                ]),
                ['http_code' => 422, 'response_headers' => ['content-type' => 'application/json']]
            );
        });

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('MailerSend only accepts this field on their Professional and Enterprise plans. Set this plugin\'s transport to SMTP if the site has to send it on a smaller plan.');

        $transport->send($this->message());
    }

    public function testARefusalWithNoFieldErrorsStillCarriesMailersendsSentence(): void
    {
        $transport = $this->transport(static function (): ResponseInterface {
            return new MockResponse(
                json_encode(['message' => 'Unauthenticated.']),
                ['http_code' => 401, 'response_headers' => ['content-type' => 'application/json']]
            );
        });

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Unable to send an email via MailerSend: Unauthenticated. (code 401).');

        $transport->send($this->message());
    }

    /**
     * A gateway between the site and MailerSend can answer HTML. The raw answer
     * is more use to whoever is reading the log than a complaint about JSON.
     */
    public function testAnAnswerThatIsNotJsonComesThroughAsItself(): void
    {
        $transport = $this->transport(static function (): ResponseInterface {
            return new MockResponse('<html><body>502 Bad Gateway</body></html>', ['http_code' => 502]);
        });

        $this->expectException(HttpTransportException::class);
        $this->expectExceptionMessage('Unable to send an email via MailerSend: <html><body>502 Bad Gateway</body></html> (code 502).');

        $transport->send($this->message());
    }

    public function testTheTransportNamesItself(): void
    {
        self::assertSame('mailersend+api://api.mailersend.com', (string)$this->transport(static function (): ResponseInterface {
            return new MockResponse('', ['http_code' => 202]);
        }));
    }

    private function transport(callable $answer): MailersendApiTransport
    {
        return new MailersendApiTransport('api-test-key', new MockHttpClient($answer));
    }

    private function message(): Email
    {
        return (new Email())
            ->from('sender@example.com')
            ->to('buyer@example.com')
            ->subject('Your order')
            ->text('Plain body');
    }
}
