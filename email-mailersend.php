<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;
use Grav\Plugin\EmailMailersend\Transport\MailersendApiTransport;
use Grav\Plugin\EmailMailersend\Transport\MailersendSmtpTransport;

/**
 * Class EmailMailersendPlugin
 * @package Grav\Plugin
 */
class EmailMailersendPlugin extends Plugin
{
    /**
     * @return array
     *
     * The getSubscribedEvents() gives the core a list of events
     *     that the plugin wants to listen to. The key of each
     *     array section is the event that the plugin listens to
     *     and the value (in the form of an array) contains the
     *     callable (or function) as well as the priority. The
     *     higher the number the higher the priority.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onEmailEngines'       => ['onEmailEngines', 0],
            'onEmailTransportDsn'  => ['onEmailTransportDsn', 0],
            'onEmailProviders'     => ['onEmailProviders', 0],
        ];
    }

    /**
     * Composer autoload
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    public function onEmailEngines(Event $e)
    {
        $engines = $e['engines'];
        $engines->mailersend = 'MailerSend';
    }

    public function onEmailTransportDsn(Event $e)
    {
        $engine = $e['engine'];
        if ($engine === 'mailersend') {
            $options = $this->config->get('plugins.email-mailersend');
            $transport = $options['transport'] ?? 'api';
            if ($transport === 'api') {
                $dsn = new MailersendApiTransport($options['api_key'] ?? '');
            } else {
                $dsn = new MailersendSmtpTransport($options['username'] ?? '', $options['password'] ?? '');
            }
            $e['dsn'] = $dsn;
            $e->stopPropagation();
        }
    }

    /**
     * Tell the Email plugin what this plugin knows about MailerSend.
     *
     * Everything MailerSend knows about itself - how its delivery webhooks are
     * verified and read, how one is created from the API key already pasted in,
     * what a sending domain's DNS has to say, what each of the two transports
     * does to custom headers - belongs here rather than in whatever add-on
     * happened to need the answer first. See the Email plugin's
     * `docs/providers.md`.
     *
     * The interface check is not ceremony. This handler only runs when
     * something fires `onEmailProviders`, which today only an Email plugin
     * carrying the contract does - but the provider class names that plugin's
     * interfaces in its `implements` clause, and autoloading a class whose
     * interface is missing is a fatal error rather than an exception. One line
     * here keeps that impossible.
     */
    public function onEmailProviders(Event $e): void
    {
        if (!interface_exists(\Grav\Plugin\Email\Providers\Provider::class)) {
            return;
        }

        $providers = $e['providers'] ?? null;

        if (!is_object($providers) || !method_exists($providers, 'add')) {
            return;
        }

        $providers->add(new \Grav\Plugin\EmailMailersend\Provider\MailerSendProvider(
            (array)$this->config->get('plugins.email-mailersend'),
            null,
            static fn (array $values) => \Grav\Plugin\EmailMailersend\Settings::save($values),
            fn (string $key): ?string => isset($this->grav['language'])
                ? (string)$this->grav['language']->translate($key)
                : null,
        ));
    }
}
