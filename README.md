# Email Mailersend Plugin

The **Email MailerSend** Plugin is an extension for [Grav CMS](https://github.com/getgrav/grav). It lets the [Email plugin](https://github.com/getgrav/grav-plugin-email) send through [MailerSend](https://www.mailersend.com), either through their Email API or through their SMTP relay, and it can read MailerSend's delivery reports back.

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

The **transport** can either be `api` or `smtp`. `api_key` is used by `api`; `username` and `password` are used by `smtp`.

Once the options are set, all other configuration regarding email should be done in the main `email` plugin. You just need to set the engine in the `email.yaml` configuration:

```yaml
mailer:
  engine: mailersend
```

A default `from:` and `to:` address is also required.

## The two transports

The plugin carries both transports itself, under `classes/Transport/`. There is no official Symfony bridge for MailerSend, so nothing is pulled in from a package.

### `api`

`POST /v1/email` on `api.mailersend.com`, authenticated with the API key. It is the faster of the two, and it is what most sites want.

It rebuilds the message as JSON, which is the one thing worth understanding about it: an API send is not the message, it is a description of the message, and anything MailerSend's body has no field for does not travel. The transport fills in `from`, `to`, `cc`, `bcc`, `reply_to`, `subject`, `text`, `html`, `attachments`, `tags`, `headers`, `list_unsubscribe`, `in_reply_to`, `references` and `send_at`.

A few things follow from that:

* **Custom headers and `List-Unsubscribe` need a Professional or Enterprise plan.** MailerSend only accepts the `headers` and `list_unsubscribe` fields on those plans, and `in_reply_to` and `references` on paid plans generally. The transport leaves each of them out entirely when the message has nothing to put in it, so a site on the free or Hobby plan never sends a field its plan would refuse. If a message *does* carry a custom header on a plan that has not got the feature, MailerSend refuses the send and the error in the Grav log says so and says to switch to SMTP.
* **Only headers you set yourself go in `headers`.** From, To, Cc, Bcc, Sender, Subject, Date, Message-ID, Received, Return-Path, Reply-To and the MIME headers are MailerSend's own and are never sent as custom headers; In-Reply-To, References, List-Unsubscribe and List-Unsubscribe-Post are sent in the fields of their own that MailerSend gives them, so nothing arrives twice.
* **`List-Unsubscribe` becomes one value.** MailerSend takes a single RFC 8058 value, so where the header carries both an https link and a `mailto:`, the https one travels — MailerSend then sets `List-Unsubscribe-Post: List-Unsubscribe=One-Click` for you, which is what Gmail is looking for.
* **Five tags at most**, of 191 characters each, which is MailerSend's limit. A sixth `TagHeader` on a message is refused rather than quietly dropped.
* **A message dated in the future is scheduled**, through `send_at`, as long as that date is within MailerSend's 72-hour window. Beyond that it goes now.
* **There is no `personalization`.** It looks like the place for message metadata and it is not — it is a cut-down Twig engine MailerSend runs over your subject, HTML and text, and handing a rendered Grav email to a second templating pass is a bad surprise waiting to happen. Metadata travels as the `X-Metadata-<key>` header Symfony spells it as, which is also exactly what arrives over SMTP.
* **MailerSend's own message id is recorded** from the `x-message-id` response header onto the sent message. It is the same id their delivery webhooks carry, so it is the handle a store has for matching an event to a send.

### `smtp`

`smtp.mailersend.net` on port 587, upgraded to TLS with STARTTLS during the handshake. The username and password are the SMTP credentials on the sending domain's page in MailerSend, not the API token.

This transport hands MailerSend the whole message, so every header reaches the wire exactly as it was written, on any plan. **A bulk sender on a small MailerSend plan wants this transport**, because a bulk send with no `List-Unsubscribe` is what a spammer looks like to Gmail.

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

**Custom headers and `List-Unsubscribe` need a bigger MailerSend plan on the API transport.** The transport sends both, but MailerSend's Email API only accepts them on their Professional and Enterprise plans. If a bulk sender on your site needs the unsubscribe headers to reach the wire — and it does, because a bulk sender with no unsubscribe button is what a spammer looks like to Gmail — either move up a plan or set this plugin's **transport** to `smtp`, where the headers are the message on every plan.

## Development

The plugin's own `vendor/` holds nothing but Composer's autoloader — Symfony Mailer comes from Grav at runtime — so the test harness keeps its own:

```
composer install -d tests
tests/vendor/bin/phpunit
```

The suite reads the provider contract straight off a checkout of the Email plugin. It looks for one beside this repository; set `EMAIL_PLUGIN_ROOT` if yours is somewhere else.

## Credits

Thanks to the [Symfony team](https://symfony.com) for making this plugin possible.