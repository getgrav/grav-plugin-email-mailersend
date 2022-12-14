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
access_key:
```

Note that if you use the Admin Plugin, a file with your configuration named email-mailersend.yaml will be saved in the `user/config/plugins/`-folder once the configuration is saved in the Admin.

## Usage

The **transport** can either be `api` (recommended) or `smtp`.  `username` and `password` is used for the `SMTP` option, and `access_key` is used by `api`.

Once the options are set, all other configuration regarding email should be done in the main `email` plugin.  You just need to set the engine in the `email.yaml` configuration:

```yaml
mailer:
  engine: mailersend
```

A default `from:` and `to:` address is also required.

## Credits

Thanks to the [Syfmony team](https://symfony.com) for making this plugin possible.