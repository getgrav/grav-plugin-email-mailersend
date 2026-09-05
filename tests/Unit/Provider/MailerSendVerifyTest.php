<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend\Tests\Unit\Provider;

use Grav\Plugin\Email\Providers\WebhookRequest;
use Grav\Plugin\EmailMailersend\Provider\MailerSendReports;
use PHPUnit\Framework\TestCase;

/**
 * The signature, computed here rather than pasted, and then broken in every way
 * it can be broken.
 *
 * A pasted signature only ever proves that a string was copied correctly. One
 * computed in the test and then attacked — a byte of the body changed, the
 * header dropped, the wrong key, the public test key against a real payload —
 * is the one that says the check does what it claims.
 */
final class MailerSendVerifyTest extends TestCase
{
    private const SECRET = 'ms_live_2f81c0d6a1b34e57bd9a0e4c7f2ab318';

    private const BODY = '{"type":"activity.hard_bounced","created_at":"2025-08-05T21:24:40.000000Z","data":{"email":"nobody@example.com"}}';

    private MailerSendReports $reports;

    protected function setUp(): void
    {
        $this->reports = new MailerSendReports();
    }

    public function testAGoodSignatureIsAccepted(): void
    {
        $verdict = $this->verify(self::BODY, $this->sign(self::BODY, self::SECRET));

        self::assertTrue($verdict->ok);
        self::assertTrue($verdict->signed);
        self::assertSame('', $verdict->reason);
        self::assertNull($verdict->confirmUrl);
    }

    public function testTheHeaderIsFoundHoweverItWasSpelled(): void
    {
        $signature = $this->sign(self::BODY, self::SECRET);

        foreach (['signature', 'Signature', 'SIGNATURE'] as $name) {
            $request = new WebhookRequest('POST', '/w', [], [strtolower($name) => $signature], self::BODY);

            self::assertTrue($this->reports->verify($request, ['signing_secret' => self::SECRET])->ok, $name);
        }
    }

    public function testAnUpperCaseDigestStillMatches(): void
    {
        $signature = strtoupper($this->sign(self::BODY, self::SECRET));

        self::assertTrue($this->verify(self::BODY, $signature)->ok);
    }

    public function testOneChangedByteOfTheBodyIsRefused(): void
    {
        $signature = $this->sign(self::BODY, self::SECRET);
        $tampered = str_replace('nobody@example.com', 'nobody@example.net', self::BODY);

        $verdict = $this->verify($tampered, $signature);

        self::assertFalse($verdict->ok);
        self::assertStringContainsString('did not match', $verdict->reason);
    }

    public function testTheWrongKeyIsRefused(): void
    {
        $verdict = $this->verify(self::BODY, $this->sign(self::BODY, 'ms_live_someone_elses_secret'));

        self::assertFalse($verdict->ok);
        self::assertStringContainsString('did not match', $verdict->reason);
    }

    public function testAMissingKeyIsRefusedAndSaysSo(): void
    {
        $request = new WebhookRequest('POST', '/w', [], ['signature' => $this->sign(self::BODY, self::SECRET)], self::BODY);

        $verdict = $this->reports->verify($request, []);

        self::assertFalse($verdict->ok);
        self::assertStringContainsString('no MailerSend signing secret is configured', $verdict->reason);
    }

    public function testAMissingSignatureHeaderIsRefusedBeforeAnythingElse(): void
    {
        $request = new WebhookRequest('POST', '/w', [], [], self::BODY);

        $verdict = $this->reports->verify($request, ['signing_secret' => self::SECRET]);

        self::assertFalse($verdict->ok);
        self::assertStringContainsString('no Signature header', $verdict->reason);
    }

    /**
     * MailerSend will not save a webhook until the address answers 2xx to a
     * `webhook.test` body, and it signs that one with a secret printed in their
     * documentation, because the real secret does not exist yet. Refusing it
     * would mean no webhook could ever be created.
     */
    public function testTheEndpointCheckIsAcceptedUnderMailerSendsPublicTestSecret(): void
    {
        $ping = '{"type":"webhook.test","message":"This is a ping test message","created_at":"2026-03-27T07:24:20.577080Z"}';

        $verdict = $this->reports->verify(
            new WebhookRequest('POST', '/w', [], ['signature' => $this->sign($ping, MailerSendReports::TEST_SECRET)], $ping),
            [],
        );

        self::assertTrue($verdict->ok);
        self::assertTrue($this->reports->parse(new WebhookRequest('POST', '/w', [], [], $ping))->isEmpty());
    }

    /**
     * And the other half of that, which is the part that matters: a published
     * secret is not a secret, so it opens exactly one door and no others.
     */
    public function testARealPayloadSignedWithThePublicTestSecretIsRefused(): void
    {
        $verdict = $this->reports->verify(
            new WebhookRequest('POST', '/w', [], ['signature' => $this->sign(self::BODY, MailerSendReports::TEST_SECRET)], self::BODY),
            ['signing_secret' => self::SECRET],
        );

        self::assertFalse($verdict->ok);
        self::assertStringContainsString('public test secret', $verdict->reason);
    }

    public function testTheVerificationKeysAreTheOneThisNeeds(): void
    {
        self::assertSame(['signing_secret'], $this->reports->verificationKeys());
    }

    // ------------------------------------------------------------- internals

    private function verify(string $body, string $signature): \Grav\Plugin\Email\Providers\Verdict
    {
        return $this->reports->verify(
            new WebhookRequest('POST', '/w', [], ['signature' => $signature], $body),
            ['signing_secret' => self::SECRET],
        );
    }

    private function sign(string $body, string $secret): string
    {
        return hash_hmac('sha256', $body, $secret);
    }
}
