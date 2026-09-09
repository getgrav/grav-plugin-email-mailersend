# v1.1.2
## 09/08/2026

1. [](#bugfix)
    * **The API key, the SMTP password and the signing secret are no longer shown in the clear.** Every credential field in this plugin was typed `text`, so an account's sending credentials were rendered as readable text on the settings page and handed to the browser unmasked by the API. They are `password` fields now.

# v1.1.1
## 09/05/2026

1. [](#bugfix)
    * **Set up now repairs a webhook whose address has changed.** A store that generated a new secret, or lost its settings, was told nothing was registered while MailerSend still held a webhook at the old address, and pressing Set up made a second one beside the dead one so every figure was counted twice. Set up now recognises the store's own webhook by its endpoint on the same domain and points it at the new address. MailerSend still only hands a signing secret back when a webhook is first created, and the message says so as before.

# v1.1.0
## 09/05/2026

1. [](#new)
    * Delivery reports: this plugin now tells the Email plugin how MailerSend's webhooks are verified and read, so an add-on that records bounces no longer carries a MailerSend parser of its own
    * A "Set up in MailerSend" button an add-on can offer, which creates the webhook from the API key already pasted in, ticks the six events, and saves the signing secret before MailerSend stops showing it
    * New settings: `signing_secret`, `sending domain` and a `domain_id` filled in for you
    * The plugin now says plainly what each of its two transports does to custom headers, so a screen can warn that `List-Unsubscribe` needs a Professional or Enterprise plan on the API transport and works on any plan over SMTP
    * The header a send id travels in is now named by the Email plugin rather than by this one. It is `X-Grav-Send-Id`, or whatever `providers.send_header` in the Email plugin's configuration says; it used to be `X-KahunaCart-Send`, which was another product's name sitting in a Team Grav plugin. MailerSend echoes no headers in any webhook, so this is documentation rather than a correlation path, and the class note says so
    * A test suite under `tests/`, run with `composer install -d tests` and `tests/vendor/bin/phpunit`
    * The plugin now carries its own MailerSend transports under `classes/Transport/`, written against MailerSend's current Email API, and no longer depends on the `rhukster/mailersend-mailer` package
    * The API transport now sends `list_unsubscribe`, `in_reply_to`, `references` and `send_at`, and leaves each of them out when the message has nothing to put in it, so a site on a smaller MailerSend plan never sends a field its plan would refuse
    * MailerSend's own message id is now recorded on the sent message, which is the id their delivery webhooks carry

1. [](#bugfix)
    * The API transport sent every header on the message — From, To, Subject, Date and the MIME headers included — as a map, where MailerSend's API wants a list of `{name, value}` objects holding only the headers a caller added by hand
    * An answer from MailerSend with no `x-message-id` header on it no longer trips over an undefined index
    * A refused send now names MailerSend's own message and the field it complained about, and says which plan a refused `headers` or `list_unsubscribe` needs
    * A sixth tag on a message is now refused rather than sent for MailerSend to reject, and tags are truncated at MailerSend's 191 characters rather than 255
    * An inline attachment now carries the content id the HTML body references, so `cid:` images resolve

# v1.0.1
## 05/01/2026

1. [](#improved)
    * Added 1.7|2.0 compatibility flags

# v1.0.0
## 05/09/2023

1. [](#new)
   * Initial public release

# v1.0.0-rc.3
##  10/12/2022

1. [](#bugfix)
   * default to empty string in config values are null

# v1.0.0-rc.2
##  10/05/2022

1. [](#bugfix)
   * Set `email` plugin dependency to `4.0.0-rc.1`
     
# v1.0.0-rc.1
##  10/05/2022

1. [](#new)
    * ChangeLog started...
