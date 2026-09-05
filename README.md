# Email Mailersend Plugin

**This README.md file should be modified to describe the features, installation, configuration, and general usage of the plugin.**

The **Email Mailersend** Plugin is an extension for [Grav CMS](https://github.com/getgrav/grav). Mailersend integration for new Email plugin

## Installation

Installing the Email Mailersend plugin can be done in one of three ways: The GPM (Grav Package Manager) installation method lets you quickly install the plugin with a simple terminal command, the manual method lets you do so via a zip file, and the admin method lets you do so via the Admin Plugin.

### GPM Installation (Preferred)

To install the plugin via the [GPM](https://learn.getgrav.org/cli-console/grav-cli-gpm), through your system's terminal (also called the command line), navigate to the root of your Grav-installation, and enter:

    bin/gpm install email-mailersend

This will install the Email Mailersend plugin into your `/user/plugins`-directory within Grav. Its files can be found under `/your/site/grav/user/plugins/email-mailersend`.

### Admin Plugin

If you use the Admin Plugin, you can install the plugin directly by browsing the `Plugins`-menu and clicking on the `Add` button.

## Configuration

Before configuring this plugin, you should copy the `user/plugins/email-mailersend/email-mailersend.yaml` to `user/config/plugins/email-mailersend.yaml` and only edit that copy.

Here is the default configuration and an explanation of available options:

```yaml
enabled: true
transport: api
username:
password:
api_key:
signing_secret:
domain:
domain_id:
```

The last three are only for delivery reports and can be left empty if you are not using them. See below.

Note that if you use the Admin Plugin, a file with your configuration named email-mailersend.yaml will be saved in the `user/config/plugins/`-folder once the configuration is saved in the Admin.

## Usage

The **transport** can either be `api` (recommended) or `smtp`.  `username` and `password` is used for the `SMTP` option, and `api_key` is used by `api`.

Once the options are set, all other configuration regarding email should be done in the main `email` plugin.  You just need to set the engine in the `email.yaml` configuration:

```yaml
mailer:
  engine: mailersend
```

A default `from:` and `to:` address is also required.

## Delivery reports

MailerSend can tell your site what happened to every message it sent — delivered, bounced, marked as spam, opened, clicked — and this plugin knows how to read those reports and prove they really came from MailerSend. Any add-on that wants them asks the Email plugin, and the Email plugin asks this plugin. Nothing else on the site needs to know a thing about MailerSend.

What a store gets out of it: an address that hard bounces or reports a message as spam stops being mailed, and the delivered, opened and clicked figures on a campaign fill themselves in. Nothing here is needed to send mail — this is only about hearing back.

### The one button

An add-on that receives delivery reports shows you a webhook address and a "Set up in MailerSend" button. Pressing it creates the webhook in your MailerSend account, ticks the right six events, and saves the signing secret into this plugin's settings for you. That last part is the reason to press the button rather than do it by hand: **MailerSend shows the signing secret once, when the webhook is created, and there is no way to see it again**. Lose it and the only way back is to delete the webhook and make another.

The API key you already pasted in for sending is the one it uses. It needs two permissions, both set on the token's own page in MailerSend under Integrations → MailerSend API → Manage:

* **Webhooks** — Full access
* **Domains** — Read only, so the sending domain can be found without you having to hunt down its id

If your MailerSend account has more than one verified sending domain, put the one this site sends from in the **Sending domain** field first. With a single domain it is worked out for you.

Pressing the button twice is safe. It looks for a webhook already pointed at the same address and updates that one rather than making a second — two webhooks posting the same events would double every figure in your reports.

### Doing it by hand

In MailerSend, open **Domains**, click **Manage** beside the domain this site sends from, and go to the **Webhooks** tab. Add a webhook, paste the address the add-on shows you into the URL box, name it anything you like, and tick **Delivered**, **Hard bounced**, **Soft bounced**, **Spam complaint**, **Opened** and **Clicked**. Save it, then copy the signing secret MailerSend shows you into the **Signing secret** field in this plugin's settings.

### Two things worth knowing

**MailerSend's webhooks carry no headers.** Whatever a plugin stamps on a message — a campaign id, a send id, the message's own `Message-ID` — none of it comes back. What comes back is MailerSend's own message id, which is the same one their SMTP relay answers with in `250 Message queued as …`. So events are matched to a recipient's address, and to that id where a store recorded it. Bounces and spam complaints do the right thing either way; per-message figures depend on which id the store kept.

**Custom headers and `List-Unsubscribe` do not survive the API transport.** MailerSend's Email API only carries them on their Professional and Enterprise plans, and this plugin does not set them. If a bulk sender on your site needs the unsubscribe headers to reach the wire — and it does, because a bulk sender with no unsubscribe button is what a spammer looks like to Gmail — set this plugin's **transport** to `smtp`, where the headers are the message.

## Development

The plugin's own `vendor/` holds nothing but Composer's autoloader and MailerSend's Symfony transport, so the test harness keeps its own:

```
composer install -d tests
tests/vendor/bin/phpunit
```

The suite reads the provider contract straight off a checkout of the Email plugin. It looks for one beside this repository; set `EMAIL_PLUGIN_ROOT` if yours is somewhere else.

## Credits

Thanks to the [Syfmony team](https://symfony.com) for making this plugin possible.