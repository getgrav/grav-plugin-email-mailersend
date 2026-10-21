<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\RawMessage;

/**
 * Sends through MailerSend's SMTP relay.
 *
 * `smtp.mailersend.net` on port 587, which is the submission port: the
 * connection opens in the clear and is upgraded with STARTTLS during the
 * handshake, which Symfony does by itself as soon as the server advertises it.
 * That is why `$tls` is false rather than true - true means implicit TLS from
 * the first byte, which is port 465, and asking for it on 587 only hangs.
 *
 * Unlike the API transport, this one hands MailerSend the whole message, so
 * every header a caller set arrives exactly as it was written - including
 * `List-Unsubscribe`, which MailerSend's Email API only carries on their bigger
 * plans. A bulk sender on a small MailerSend plan wants this transport.
 *
 * The username and password are the SMTP credentials MailerSend shows on the
 * sending domain's page, not the API token.
 *
 * ## A 421 is sent again, once
 *
 * MailerSend's relay now and then answers a command on a connection it has
 * kept open with `421 Service not available, closing transmission channel`
 * and hangs up. Nothing was accepted, so the message is safe to send again,
 * and on a bulk send about one in six messages was hitting it. Symfony already
 * knows what to do with the dead connection: after a failed send it pings
 * before the next one, finds nobody there and opens a fresh connection. So
 * the second attempt goes out on a new connection. A second 421 is thrown as
 * it always was, because a relay that refuses a fresh connection too is down,
 * not tidying up.
 */
class MailersendSmtpTransport extends EsmtpTransport
{
    public const HOST = 'smtp.mailersend.net';
    public const PORT = 587;

    public function __construct(string $username, string $password, int $port = self::PORT, bool $tls = false, ?EventDispatcherInterface $dispatcher = null, ?LoggerInterface $logger = null)
    {
        parent::__construct(self::HOST, $port, $tls, $dispatcher, $logger);

        $this->setUsername($username);
        $this->setPassword($password);
    }

    /** "Service not available, closing transmission channel". */
    public const CLOSING_CHANNEL = 421;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        try {
            return parent::send($message, $envelope);
        } catch (TransportExceptionInterface $e) {
            if ($e->getCode() !== self::CLOSING_CHANNEL) {
                throw $e;
            }

            $this->getLogger()->info('MailerSend closed the SMTP connection with a 421, sending once more on a new connection');

            return parent::send($message, $envelope);
        }
    }
}
