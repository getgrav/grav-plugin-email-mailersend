# v1.1.0
## 09/05/2026

1. [](#new)
    * Delivery reports: this plugin now tells the Email plugin how MailerSend's webhooks are verified and read, so an add-on that records bounces no longer carries a MailerSend parser of its own
    * A "Set up in MailerSend" button an add-on can offer, which creates the webhook from the API key already pasted in, ticks the six events, and saves the signing secret before MailerSend stops showing it
    * New settings: `signing_secret`, `sending domain` and a `domain_id` filled in for you
    * The plugin now says plainly what each of its two transports does to custom headers, so a screen can warn that `List-Unsubscribe` is dropped by the API transport
    * A test suite under `tests/`, run with `composer install -d tests` and `tests/vendor/bin/phpunit`

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
