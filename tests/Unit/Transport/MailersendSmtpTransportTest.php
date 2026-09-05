<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Tests\Unit\Transport;

use Grav\Plugin\EmailMailersend\Transport\MailersendSmtpTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * The SMTP transport is four lines, and every one of them is a value that
 * breaks sending if it drifts.
 */
final class MailersendSmtpTransportTest extends TestCase
{
    public function testItPointsAtMailersendsRelayOnTheSubmissionPort(): void
    {
        $stream = (new MailersendSmtpTransport('smtp-user', 'smtp-password'))->getStream();

        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertSame('smtp.mailersend.net', $stream->getHost());
        self::assertSame(587, $stream->getPort());
    }

    /**
     * Port 587 opens in the clear and is upgraded with STARTTLS during the
     * handshake, which Symfony does by itself. Implicit TLS from the first byte
     * is port 465, and asking for it here only hangs.
     */
    public function testTheConnectionIsNotImplicitTlsBecauseThatIsPortFourSixFive(): void
    {
        $stream = (new MailersendSmtpTransport('smtp-user', 'smtp-password'))->getStream();

        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertFalse($stream->isTLS());
    }

    public function testTheCredentialsArePutOnTheTransport(): void
    {
        $transport = new MailersendSmtpTransport('smtp-user', 'smtp-password');

        self::assertSame('smtp-user', $transport->getUsername());
        self::assertSame('smtp-password', $transport->getPassword());
    }

    /**
     * 587 is the only port MailerSend documents, but a host that blocks it is a
     * support ticket rather than a code change, so the port stays a parameter.
     */
    public function testThePortCanBeOverriddenForAHostThatBlocksFiveEightSeven(): void
    {
        $stream = (new MailersendSmtpTransport('smtp-user', 'smtp-password', 2525))->getStream();

        self::assertInstanceOf(SocketStream::class, $stream);
        self::assertSame(2525, $stream->getPort());
    }
}
