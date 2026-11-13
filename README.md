# Lockally Mail

WordPress plugin that sends the site's mail through
[Lockally](https://lockally.com), so it arrives from your own verified domain
instead of from the web server.

It replaces sending only: everything that calls `wp_mail()` keeps working as it
did, including WooCommerce, contact forms and your own code.

The listing text is in [readme.txt](readme.txt), which is what WordPress.org
renders.

## How it works

`pre_wp_mail`, the filter WordPress added in 5.7 for exactly this, takes the
message before core reaches PHPMailer, so nothing is sent twice and nothing
depends on the host's mail setup or on an open SMTP port. The message goes to
`POST /v1/send` over HTTPS.

Two things worth knowing about the design:

* **It does nothing until it is configured.** Sending is taken over only when
  both an API key and a From address are saved. A plugin that captured
  `wp_mail()` and then failed because it was never set up would break password
  resets on the site that installed it.
* **It stands aside if another plugin got there first.** If something earlier on
  the filter already handled the message, this one returns that decision
  untouched rather than sending a second copy.

## Layout

| File | What it does |
|---|---|
| `lockally-mail.php` | Plugin header and wiring |
| `includes/class-client.php` | The only place that calls the API, and the wording of a refusal |
| `includes/class-mailer.php` | `wp_mail()` arguments to a Lockally send, including the header parsing |
| `includes/class-settings.php` | The settings screen, the test message, recent failures |
| `uninstall.php` | Removes both options when the plugin is deleted |

## Development

```bash
composer install
composer run lint     # WordPress coding standards, which the plugin review runs
composer run test     # header parsing, on plain PHP with no WordPress
```

## Building the zip for WordPress.org

```bash
git archive --format=zip --prefix=lockally-mail/ -o lockally-mail.zip HEAD
```

`.gitattributes` keeps the development files (tests, CI, Composer and phpcs
config, this README) out of the archive, which Plugin Check requires. Commit
first: the archive is built from `HEAD`, not the working tree.

The header parsing is the part of `wp_mail()` that plugins most often get
wrong, so it is deliberately free of WordPress functions and covered by tests
that run without loading WordPress.

## Support

support@lockally.com, or the [developer documentation](https://developers.lockally.com).

## Licence

GPL-2.0-or-later, as WordPress.org requires. See [LICENSE](LICENSE).
