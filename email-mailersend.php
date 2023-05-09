<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Mailer\Bridge\Mailersend\Transport\MailersendApiTransport;
use Symfony\Component\Mailer\Bridge\Mailersend\Transport\MailersendSmtpTransport;

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
            if ($options['transport'] === 'api') {
                $dsn = new MailersendApiTransport($options['api_key'] ?? '');
            } else {
                $dsn = new MailersendSmtpTransport($options['username'] ?? '', $options['password'] ?? '');
            }
            $e['dsn'] = $dsn;
            $e->stopPropagation();
        }
    }
}
