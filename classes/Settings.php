<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailersend;

use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Grav;

/**
 * Writing a value back into this plugin's own settings.
 *
 * One job, and it exists because of one field: MailerSend hands the webhook's
 * signing secret back exactly once, in the answer to the call that created the
 * webhook, and nothing anywhere will ever tell you it again. A setup button that
 * printed it on screen and asked somebody to paste it into the next field along
 * would lose it about a third of the time, and the way back from that is
 * deleting the webhook and starting over.
 *
 * The whole of the Grav-facing part of this plugin's provider is here, which is
 * what keeps {@see Provider\MailerSendProvider} and everything under it testable
 * with nothing running.
 */
final class Settings
{
    /** This plugin's slug, which is also its config file's name. */
    public const SLUG = 'email-mailersend';

    private function __construct()
    {
    }

    /**
     * Merge values into `user/config/plugins/email-mailersend.yaml`.
     *
     * The file is read, merged and written rather than replaced, because a
     * merchant's API key and transport choice are in it and a setup button has
     * no business touching either.
     *
     * @param array<string, mixed> $values
     */
    public static function save(array $values): void
    {
        if ($values === []) {
            return;
        }

        $grav = Grav::instance();
        $locator = $grav['locator'] ?? null;

        if ($locator === null) {
            return;
        }

        $path = $locator->findResource('config://plugins/' . self::SLUG . '.yaml', true, true);

        if (!\is_string($path) || $path === '') {
            return;
        }

        $file = CompiledYamlFile::instance($path);
        $content = $file->content();
        $file->save(array_replace(\is_array($content) ? $content : [], $values));
        $file->free();

        // The saved file is not read again until the next request, and the
        // button's own answer is drawn on this one — so the running config is
        // updated too, or a screen that redraws itself would still say the
        // signing secret is missing.
        $config = $grav['config'] ?? null;

        if ($config !== null) {
            foreach ($values as $key => $value) {
                $config->set('plugins.' . self::SLUG . '.' . $key, $value);
            }
        }
    }
}
