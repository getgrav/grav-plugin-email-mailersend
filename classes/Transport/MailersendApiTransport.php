<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Transport;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Sends through MailerSend's Email API, `POST /v1/email`.
 *
 * Written against MailerSend's own reference, read 2026-09-05. There is no
 * official Symfony bridge for MailerSend - `symfony/mailersend-mailer` does not
 * exist - so this is the plugin's own, and it replaces the
 * `rhukster/mailersend-mailer` package the plugin used to depend on.
 *
 * ## The API rebuilds the message, so a header only arrives if it is in here
 *
 * Over SMTP the headers *are* the message and anything a caller sets reaches
 * the wire. Here the message is turned into a JSON body, and everything that
 * body has no field for is gone. That is why the header handling below is
 * fussy: `headers` carries exactly the headers a caller added by hand, and the
 * ones MailerSend expresses as fields of their own are kept out of it so that
 * nothing arrives twice.
 *
 * ## Two fields MailerSend only honours on the bigger plans
 *
 * `headers` and `list_unsubscribe` are **Professional and Enterprise only** -
 * MailerSend says so on both the API reference and the help centre. So is
 * `in_reply_to`, and so is `references`; those two are paid plans generally.
 * Every one of them is left out of the payload when there is nothing to put in
 * it, so a store on the free plan never sends a field its plan would refuse.
 * A store that *does* set a custom header on the free plan gets a 422, and
 * {@see errorMessage()} says in plain words what to do about it.
 *
 * ## Why there is no `personalization`
 *
 * MailerSend's `personalization` looks like the place to put a Symfony
 * `MetadataHeader`, and it is not. It is a cut-down Twig engine that MailerSend
 * runs over the subject, the HTML and the text, so filling it in would hand a
 * store's rendered email body to a second templating pass on MailerSend's
 * servers - which is a genuinely bad surprise for a Grav site, where an email
 * about Twig may well have `{{ ... }}` in it on purpose. Metadata is carried as
 * the `X-Metadata-<key>` header Symfony spells it as, which is also exactly what
 * arrives over the SMTP transport, so the two paths agree.
 */
class MailersendApiTransport extends AbstractApiTransport
{
    private const HOST = 'api.mailersend.com';
    private const ENDPOINT = '/v1/email';

    /** MailerSend accepts at most five tags, of at most 191 characters each. */
    private const MAX_TAGS = 5;
    private const MAX_TAG_LENGTH = 191;

    /** `list_unsubscribe` takes a single RFC 8058 value of at most 990 characters. */
    private const MAX_LIST_UNSUBSCRIBE_LENGTH = 990;

    /** `in_reply_to` takes at most 998 characters, the RFC 5322 line limit. */
    private const MAX_IN_REPLY_TO_LENGTH = 998;

    /** MailerSend's answer to a send it accepted. */
    private const ACCEPTED = 202;

    /**
     * Headers MailerSend either writes itself or refuses to have overwritten,
     * plus the ones this payload already expresses as a field of its own.
     *
     * The first group is MailerSend's own list, verbatim: From, To, Cc,
     * Subject, Date, Message-ID, Received, Return-Path, Reply-To, MIME-Version,
     * Content-Type and Content-Transfer-Encoding. Bcc and Sender are added
     * because the envelope owns them. In-Reply-To, References, List-Unsubscribe
     * and List-Unsubscribe-Post are added because they go in fields of their
     * own further down, and sending them twice would put two of each on the
     * delivered mail.
     *
     * @var list<string>
     */
    private const RESERVED_HEADERS = [
        'from',
        'to',
        'cc',
        'bcc',
        'sender',
        'subject',
        'date',
        'message-id',
        'received',
        'return-path',
        'reply-to',
        'mime-version',
        'content-type',
        'content-transfer-encoding',
        'in-reply-to',
        'references',
        'list-unsubscribe',
        'list-unsubscribe-post',
    ];

    /** @var string */
    private $key;

    /**
     * @param string $key a MailerSend API token
     */
    public function __construct(string $key, ?HttpClientInterface $client = null, ?EventDispatcherInterface $dispatcher = null, ?LoggerInterface $logger = null)
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
        $response = $this->client->request('POST', sprintf('https://%s%s', $this->getEndpoint(), self::ENDPOINT), [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->key,
            ],
            'json' => $this->getPayload($email, $envelope),
        ]);

        try {
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach MailerSend.', $response, 0, $e);
        }

        if (self::ACCEPTED !== $statusCode) {
            $body = null;

            try {
                $body = $response->toArray(false);
            } catch (DecodingExceptionInterface $e) {
                // Not JSON. The raw body is more use than a decoding complaint.
            } catch (TransportExceptionInterface $e) {
                throw new HttpTransportException('Could not reach MailerSend.', $response, 0, $e);
            }

            if (!is_array($body)) {
                throw new HttpTransportException(
                    sprintf('Unable to send an email via MailerSend: %s (code %d).', $this->rawBody($response), $statusCode),
                    $response
                );
            }

            throw new HttpTransportException(
                sprintf('Unable to send an email via MailerSend: %s (code %d).', $this->errorMessage($body), $statusCode),
                $response
            );
        }

        // MailerSend's own message id, returned as a response header rather than
        // in the body, which is empty on success. It is the same id their SMTP
        // relay answers with in `250 Message queued as ...` and the same one
        // their delivery webhooks carry, so recording it is the only way a store
        // can tie an event back to a send - MailerSend echoes no headers.
        $messageId = $this->messageId($response);

        if ($messageId !== null) {
            $sentMessage->setMessageId($messageId);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getPayload(Email $email, Envelope $envelope): array
    {
        $payload = [
            'from' => $this->stringifyAddress($envelope->getSender()),
            'to' => array_map([$this, 'stringifyAddress'], $this->getRecipients($email, $envelope)),
            'subject' => (string)$email->getSubject(),
        ];

        $text = $email->getTextBody();
        if (is_string($text) && $text !== '') {
            $payload['text'] = $text;
        }

        $html = $email->getHtmlBody();
        if (is_string($html) && $html !== '') {
            $payload['html'] = $html;
        }

        if ($cc = array_map([$this, 'stringifyAddress'], $email->getCc())) {
            $payload['cc'] = $cc;
        }

        if ($bcc = array_map([$this, 'stringifyAddress'], $email->getBcc())) {
            $payload['bcc'] = $bcc;
        }

        // A Symfony message can carry several reply-to addresses. MailerSend's
        // `reply_to` is one object, not a list, so the first one is the one
        // that travels.
        $replyTo = $email->getReplyTo();
        if ($replyTo) {
            $payload['reply_to'] = $this->stringifyAddress($replyTo[0]);
        }

        if ($attachments = $this->getAttachments($email)) {
            $payload['attachments'] = $attachments;
        }

        [$tags, $headers] = $this->splitHeaders($email);

        if ($tags) {
            $payload['tags'] = $tags;
        }

        if ($headers) {
            $payload['headers'] = $headers;
        }

        if (null !== $listUnsubscribe = $this->listUnsubscribe($email)) {
            $payload['list_unsubscribe'] = $listUnsubscribe;
        }

        if (null !== $inReplyTo = $this->headerValue($email, 'In-Reply-To', self::MAX_IN_REPLY_TO_LENGTH)) {
            $payload['in_reply_to'] = $inReplyTo;
        }

        if ($references = $this->references($email)) {
            $payload['references'] = $references;
        }

        if (null !== $sendAt = $this->sendAt($email)) {
            $payload['send_at'] = $sendAt;
        }

        return $payload;
    }

    /**
     * MailerSend wants `{"email": "...", "name": "..."}`, and refuses a name
     * carrying a semicolon or a comma.
     *
     * @return array<string, string>
     */
    protected function stringifyAddress(Address $address): array
    {
        $stringified = ['email' => $address->getAddress()];

        $name = trim(str_replace([';', ','], ' ', $address->getName()));

        if ($name !== '') {
            $stringified['name'] = $name;
        }

        return $stringified;
    }

    /**
     * Tags out of `TagHeader`, and every header a caller added by hand into the
     * `headers` list.
     *
     * MailerSend validates a header name as alphanumeric with hyphens; their
     * help centre also allows underscores. Anything outside both is not a real
     * header name and would only earn a 422 on the whole send, so it is left
     * out. Values are sent as they were written - the help centre says values
     * should carry no spaces, which cannot be right of a header like
     * `Auto-Submitted: auto-generated`, and truncating somebody's value to fit a
     * sentence in a help article would be worse than letting MailerSend answer.
     *
     * @return array{0: list<string>, 1: list<array{name: string, value: string}>}
     */
    private function splitHeaders(Email $email): array
    {
        $tags = [];
        $headers = [];

        foreach ($email->getHeaders()->all() as $header) {
            if ($header instanceof TagHeader) {
                if (count($tags) >= self::MAX_TAGS) {
                    throw new TransportException(sprintf('Too many "%s" instances on the email. MailerSend accepts at most %d tags.', TagHeader::class, self::MAX_TAGS));
                }

                $tags[] = mb_substr($header->getValue(), 0, self::MAX_TAG_LENGTH);
                continue;
            }

            $name = $header->getName();

            if (in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
                continue;
            }

            if (!preg_match('/^[A-Za-z0-9_-]+$/', $name)) {
                continue;
            }

            $headers[] = [
                'name' => $name,
                'value' => $header->getBodyAsString(),
            ];
        }

        return [$tags, $headers];
    }

    /**
     * The unsubscribe address, as the one value MailerSend's `list_unsubscribe`
     * takes.
     *
     * The header itself usually holds two, `<https://...>, <mailto:...>`. The
     * https one is preferred because MailerSend sets `List-Unsubscribe-Post:
     * List-Unsubscribe=One-Click` for you when it is given a URL, which is the
     * whole point of RFC 8058 and the thing Gmail looks for on a bulk send. A
     * mailto-only header travels as the mailto.
     */
    private function listUnsubscribe(Email $email): ?string
    {
        $header = $email->getHeaders()->get('List-Unsubscribe');

        if ($header === null) {
            return null;
        }

        $value = trim($header->getBodyAsString());

        if ($value === '') {
            return null;
        }

        $candidates = [];

        if (preg_match_all('/<([^>]+)>/', $value, $matches)) {
            $candidates = array_map('trim', $matches[1]);
        } else {
            $candidates = [$value];
        }

        $candidates = array_values(array_filter($candidates, static function ($candidate) {
            return $candidate !== '';
        }));

        if (!$candidates) {
            return null;
        }

        $chosen = $candidates[0];

        foreach ($candidates as $candidate) {
            if (stripos($candidate, 'http') === 0) {
                $chosen = $candidate;
                break;
            }
        }

        $wrapped = '<' . $chosen . '>';

        // Too long to send. Better to leave the field out than to have
        // MailerSend refuse the whole message over it.
        if (strlen($wrapped) > self::MAX_LIST_UNSUBSCRIBE_LENGTH) {
            return null;
        }

        return $wrapped;
    }

    /**
     * The message ids this one is threaded under, for MailerSend's `references`.
     *
     * @return list<string>
     */
    private function references(Email $email): array
    {
        $header = $email->getHeaders()->get('References');

        if ($header === null) {
            return [];
        }

        $value = trim($header->getBodyAsString());

        if ($value === '') {
            return [];
        }

        if (preg_match_all('/<([^>]+)>/', $value, $matches)) {
            return array_values(array_filter(array_map('trim', $matches[1]), static function ($id) {
                return $id !== '';
            }));
        }

        return [$value];
    }

    /**
     * A header's value, or null when it is missing, empty or too long to send.
     */
    private function headerValue(Email $email, string $name, int $maxLength): ?string
    {
        $header = $email->getHeaders()->get($name);

        if ($header === null) {
            return null;
        }

        $value = trim($header->getBodyAsString());

        if ($value === '' || strlen($value) > $maxLength) {
            return null;
        }

        return $value;
    }

    /**
     * When to send, as a Unix timestamp, for a message dated in the future.
     *
     * MailerSend will not hold a message for more than 72 hours, so a date
     * beyond that is left off rather than sent to be refused - the message goes
     * now, which is what a transport that could not schedule would have done
     * anyway.
     */
    private function sendAt(Email $email): ?int
    {
        $date = $email->getDate();

        if ($date === null) {
            return null;
        }

        $timestamp = $date->getTimestamp();
        $now = time();

        if ($timestamp <= $now || $timestamp > $now + (72 * 3600)) {
            return null;
        }

        return $timestamp;
    }

    /**
     * @return list<array<string, string>>
     */
    private function getAttachments(Email $email): array
    {
        $attachments = [];

        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename');
            $disposition = $headers->getHeaderBody('Content-Disposition');

            $part = [
                'content' => base64_encode($attachment->getBody()),
                'filename' => ($filename === null || $filename === '') ? 'file' : $filename,
                'disposition' => $disposition === 'inline' ? 'inline' : 'attachment',
            ];

            // An inline part is only reachable from the HTML body through its
            // Content-ID, so that is what MailerSend has to be given - the
            // filename would leave every `cid:` reference in the body pointing
            // at nothing.
            if ($disposition === 'inline') {
                $part['id'] = $this->contentId($attachment->getPreparedHeaders()->getHeaderBody('Content-ID'), $part['filename']);
            }

            $attachments[] = $part;
        }

        return $attachments;
    }

    /**
     * @param mixed $contentId
     */
    private function contentId($contentId, string $fallback): string
    {
        if (!is_string($contentId) || trim($contentId) === '') {
            return $fallback;
        }

        return trim($contentId, " \t\n\r\0\x0B<>");
    }

    /**
     * MailerSend's answer when it refused the send, read into one sentence.
     *
     * Their body is `{"message": "...", "errors": {"field": ["why", ...]}}`, and
     * the field name is the useful half - "The given data was invalid" on its
     * own tells a merchant nothing. The plan note is added when the refused
     * field is one of the four MailerSend reserves for their paid plans, because
     * a 422 on `headers` reads as a bug in the site right up until somebody
     * knows that.
     *
     * @param array<string, mixed> $body
     */
    private function errorMessage(array $body): string
    {
        $message = isset($body['message']) && is_string($body['message']) ? trim($body['message']) : '';
        $errors = isset($body['errors']) && is_array($body['errors']) ? $body['errors'] : [];

        $field = null;
        $reason = null;

        foreach ($errors as $name => $reasons) {
            $field = (string)$name;
            $reason = is_array($reasons) ? (string)reset($reasons) : (string)$reasons;
            break;
        }

        if ($field !== null) {
            $message = $message === ''
                ? sprintf('%s: %s', $field, $reason)
                : sprintf('%s (%s: %s)', $message, $field, $reason);

            $planFields = ['headers', 'list_unsubscribe', 'in_reply_to', 'references'];
            $base = strtok($field, '.');

            if (in_array($base === false ? $field : $base, $planFields, true)) {
                $message .= ' MailerSend only accepts this field on their Professional and Enterprise plans. Set this plugin\'s transport to SMTP if the site has to send it on a smaller plan.';
            }
        }

        return $message === '' ? 'Unknown error' : $message;
    }

    private function messageId(ResponseInterface $response): ?string
    {
        try {
            $headers = $response->getHeaders(false);
        } catch (TransportExceptionInterface $e) {
            return null;
        }

        $id = $headers['x-message-id'][0] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function rawBody(ResponseInterface $response): string
    {
        try {
            $content = trim($response->getContent(false));
        } catch (\Throwable $e) {
            return 'no readable answer';
        }

        return $content === '' ? 'no readable answer' : $content;
    }

    private function getEndpoint(): string
    {
        return ($this->host ?: self::HOST) . ($this->port ? ':' . $this->port : '');
    }
}
