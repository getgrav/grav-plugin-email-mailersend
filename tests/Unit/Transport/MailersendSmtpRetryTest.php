<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Tests\Unit\Transport;

use Grav\Plugin\EmailMailersend\Transport\MailersendSmtpTransport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\AbstractStream;
use Symfony\Component\Mime\Email;

/**
 * MailerSend's relay sometimes answers `421 closing transmission channel` on a
 * connection it kept open. The message goes again, once, on a new connection.
 *
 * The relay is a scripted stream: each line it is handed back is the server's
 * next answer, in order, and every command written to it is recorded.
 */
final class MailersendSmtpRetryTest extends TestCase
{
    public function testA421IsSentAgainOnANewConnection(): void
    {
        $relay = new ScriptedRelay([
            // first connection: greeting, EHLO, MAIL FROM, then RCPT TO is refused
            '220 hello', '250 ok', '250 ok', '421 Service not available, closing transmission channel',
            // Symfony's RSET on the dead connection
            '421 closing',
            // the retry pings first, gets nothing useful, QUIT fails, connection dropped
            '421 closing', '421 closing',
            // second connection: greeting, EHLO, MAIL FROM, RCPT TO, DATA, end of data
            '220 hello', '250 ok', '250 ok', '250 ok', '354 go ahead', '250 Message queued as abc123',
        ]);

        $sent = self::transport($relay)->send(self::email());

        self::assertNotNull($sent);
        self::assertSame(2, $relay->connections, 'the retry opens a second connection');
        self::assertSame(2, $relay->count('MAIL FROM'), 'the message is offered twice');
        self::assertSame(1, $relay->count('DATA'), 'and its body only goes over once');
    }

    public function testASecond421IsThrown(): void
    {
        $relay = new ScriptedRelay([
            '220 hello', '250 ok', '250 ok', '421 closing',
            '421 closing',
            '421 closing', '421 closing',
            '220 hello', '250 ok', '250 ok', '421 closing',
            '421 closing',
        ]);

        try {
            self::transport($relay)->send(self::email());
            self::fail('A relay that refuses a fresh connection too has to be reported.');
        } catch (TransportException $e) {
            self::assertSame(421, $e->getCode());
            self::assertSame(2, $relay->connections, 'one retry, not a loop');
        }
    }

    public function testAnyOtherRefusalIsNotRetried(): void
    {
        $relay = new ScriptedRelay([
            '220 hello', '250 ok', '250 ok', '550 no such mailbox',
            '250 reset',
        ]);

        try {
            self::transport($relay)->send(self::email());
            self::fail('A 550 is the recipient, and sending again would not change it.');
        } catch (TransportException $e) {
            self::assertSame(550, $e->getCode());
            self::assertSame(1, $relay->connections);
            self::assertSame(1, $relay->count('MAIL FROM'));
        }
    }

    private static function transport(ScriptedRelay $relay): MailersendSmtpTransport
    {
        // No credentials, so no AUTH in the script.
        $transport = new MailersendSmtpTransport('', '');
        // `stream` is private to SmtpTransport, so the closure takes that scope.
        \Closure::bind(function (AbstractStream $stream): void {
            $this->stream = $stream;
        }, $transport, SmtpTransport::class)($relay);

        return $transport;
    }

    private static function email(): Email
    {
        return (new Email())
            ->from('contact@example.com')
            ->to('reader@example.com')
            ->subject('Hello')
            ->text('Hi');
    }
}

/** An SMTP server that answers from a script and remembers what it was told. */
final class ScriptedRelay extends AbstractStream
{
    public int $connections = 0;

    /** @var list<string> */
    public array $commands = [];

    /** @param list<string> $answers */
    public function __construct(private array $answers)
    {
    }

    public function initialize(): void
    {
        $this->connections++;
    }

    public function write(string $bytes, bool $debug = true): void
    {
        $this->commands[] = $bytes;
    }

    public function flush(): void
    {
    }

    public function terminate(): void
    {
    }

    public function readLine(): string
    {
        if ($this->answers === []) {
            throw new TransportException('The script ran out of answers.');
        }

        return array_shift($this->answers) . "\r\n";
    }

    public function count(string $verb): int
    {
        return count(array_filter($this->commands, static fn (string $c): bool => str_starts_with($c, $verb)));
    }

    protected function getReadConnectionDescription(): string
    {
        return 'scripted relay';
    }
}
