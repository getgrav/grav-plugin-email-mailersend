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

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class MailersendApiTransport extends AbstractApiTransport
{
    private const HOST = 'api.mailersend.com';
    private const API_VERSION = '1';
    private $key;

    public function __construct(string $key, HttpClientInterface $client = null, EventDispatcherInterface $dispatcher = null, LoggerInterface $logger = null)
    {
        $this->key = $key;

        parent::__construct($client, $dispatcher, $logger);
    }

    public function __toString(): string
    {
        return sprintf('mailersend+api://%s', $this->getEndpoint());
    }

    protected function doSendApi(SentMessage $sentMessage, Email $email, Envelope $envelope): ResponseInterface
    {
        $response = $this->client->request('POST', sprintf('https://%s/v%s/email', $this->getEndpoint(), self::API_VERSION), [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$this->key,
            ],
            'json' => $this->getPayload($email, $envelope),
        ]);

        try {
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote Mailersend server.', $response, 0, $e);
        }

        if (202 !== $statusCode) {
            try {
                $result = $response->toArray(false);

                throw new HttpTransportException('Unable to send an email: '.implode('; ', array_column($result['errors'], 'message')).sprintf(' (code %d).', $statusCode), $response);
            } catch (DecodingExceptionInterface $e) {
                throw new HttpTransportException('Unable to send an email: '.$response->getContent(false).sprintf(' (code %d).', $statusCode), $response, 0, $e);
            }
        }

        $sentMessage->setMessageId($response->getHeaders(false)['x-message-id'][0]);

        return $response;
    }

    private function getPayload(Email $email, Envelope $envelope): array
    {
        $addressStringifier = function (Address $address) {
            $stringified = ['email' => $address->getAddress()];

            if ($address->getName()) {
                $stringified['name'] = $address->getName();
            }

            return $stringified;
        };

        $payload = [
            'subject' => $email->getSubject(),
            'from' => $addressStringifier($envelope->getSender()),
            'to' => array_map($addressStringifier, $this->getRecipients($email, $envelope)),
        ];

        if ($email->getTextBody()) {
            $payload['text'] = $email->getTextBody();
        }
        if ($email->getHtmlBody()) {
            $payload['html'] = $email->getHtmlBody();
        }

        if ($email->getAttachments()) {
            $payload['attachments'] = $this->getAttachments($email);
        }

        if ($emails = array_map($addressStringifier, $email->getCc())) {
            $payload['cc'] = $emails;
        }
        if ($emails = array_map($addressStringifier, $email->getBcc())) {
            $payload['bcc'] = $emails;
        }
        if ($emails = array_map($addressStringifier, $email->getReplyTo())) {
            // Email class supports an array of reply-to addresses,
            // but SendGrid only supports a single address
            $payload['reply_to'] = $emails[0];
        }

        $tags = [];

        foreach ($email->getHeaders()->all() as $name => $header) {

            if ($header instanceof TagHeader) {
                if (\count($tags) > 5) {
                    throw new TransportException(sprintf('Too many "%s" instances present in the email headers. MailerSend does not accept more than 5 tags on an email.', TagHeader::class));
                }
                $tags[] = mb_substr($header->getValue(), 0, 255);
            } else {
                $payload['headers'][$header->getName()] = $header->getBodyAsString();
            }
        }

        if (\count($tags) > 0) {
            $payload['tags'] = $tags;
        }


        return $payload;
    }

    private function getAttachments(Email $email): array
    {
        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename');
            $disposition = $headers->getHeaderBody('Content-Disposition');

            $att = [
                'content' => str_replace("\r\n", '', $attachment->bodyToString()),
                'filename' => $filename,
                'disposition' => $disposition,
            ];

            if ('inline' === $disposition) {
                $att['id'] = $filename;
            }

            $attachments[] = $att;
        }

        return $attachments;
    }

    private function getEndpoint(): ?string
    {
        return ($this->host ?: self::HOST).($this->port ? ':'.$this->port : '');
    }
}
