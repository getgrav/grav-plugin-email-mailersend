<?php

declare(strict_types=1);

/**
 * The plugin ships its own vendor directory, and that directory holds nothing
 * but Composer's autoloader - Symfony Mailer comes from Grav at runtime, which
 * is why the plugin's composer.json replaces it rather than requiring it, and
 * the two transports under classes/Transport are the plugin's own rather than a
 * package.
 *
 * Installing PHPUnit and a real Symfony Mailer into that same directory would
 * put development packages into the released plugin, so the suite keeps its own
 * composer.json and its own vendor directory here under tests/ instead. Run
 * `composer install -d tests` once, then `phpunit` from the repository root.
 */
$autoload = __DIR__ . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "The test dependencies are not installed. Run: composer install -d tests\n");
    exit(1);
}

require $autoload;

/**
 * The provider contract lives in the Email plugin, not here.
 *
 * The classes under `Grav\Plugin\Email\Providers` are interfaces and value
 * objects with no Grav in them, so the suite loads them straight off a checkout
 * of that plugin. `EMAIL_PLUGIN_ROOT` points at one, and setting it to somewhere
 * that has no contract in it is an error rather than a quiet fall back to
 * another checkout — somebody who set that variable meant it. Without it the
 * usual two places are tried, which covers a plain sibling checkout and this
 * repository opened as a git worktree under a `_wt/` directory.
 */
$named = getenv('EMAIL_PLUGIN_ROOT') ?: null;

$candidates = $named !== null ? [$named] : [
    dirname(__DIR__, 2) . '/grav-plugin-email',
    dirname(__DIR__, 3) . '/grav-plugin-email',
];

$emailPluginRoot = null;

foreach ($candidates as $candidate) {
    if (is_dir(rtrim((string)$candidate, '/') . '/classes/Providers')) {
        $emailPluginRoot = rtrim((string)$candidate, '/');
        break;
    }
}

if ($emailPluginRoot === null) {
    fwrite(STDERR, sprintf(
        "The Email plugin's provider contract could not be found. Looked in:\n  %s\nSet EMAIL_PLUGIN_ROOT to a checkout of grav-plugin-email whose develop carries classes/Providers.\n",
        implode("\n  ", $candidates),
    ));
    exit(1);
}

spl_autoload_register(static function (string $class) use ($emailPluginRoot): void {
    $prefix = 'Grav\\Plugin\\Email\\Providers\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $file = $emailPluginRoot . '/classes/Providers/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
