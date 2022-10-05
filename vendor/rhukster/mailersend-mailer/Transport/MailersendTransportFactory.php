<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Mailer\Bridge\Mailersend\Transport;

use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

class MailersendTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        $scheme = $dsn->getScheme();
        $user = $this->getUser($dsn);
        $password = $this->getPassword($dsn);
        $host = 'default' === $dsn->getHost() ? null : $dsn->getHost();

        if ('mailersend+api' === $scheme) {
            return (new MailersendApiTransport($user, $this->client, $this->dispatcher, $this->logger))->setHost($host);
        }

        if (\in_array($scheme, ['mailersend+smtp', 'mailersend+smtps', 'mailersend'])) {
            return new MailersendSmtpTransport($user, $password, $this->dispatcher, $this->logger);
        }

        throw new UnsupportedSchemeException($dsn, 'mailersend', $this->getSupportedSchemes());
    }

    protected function getSupportedSchemes(): array
    {
        return ['mailersend', 'mailersend+api', 'mailersend+smtp', 'mailersend+smtps'];
    }
}
