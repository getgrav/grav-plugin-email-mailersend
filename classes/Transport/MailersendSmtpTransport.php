<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

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
}
