=== Lockally Mail ===
Contributors: lockally
Tags: smtp, email, wp-mail, deliverability, transactional email
Requires at least: 5.7
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sends the mail your site sends through Lockally, so it arrives from your own domain instead of the web server.

== Description ==

By default WordPress hands its mail to whatever the web server provides. That mail often arrives in Junk, or nowhere at all, because it comes from a shared machine with no reputation and nothing in your domain's DNS vouching for it. Password resets, order confirmations and contact form messages are the ones people notice missing.

This plugin sends that mail through [Lockally](https://lockally.com) instead, using your own verified domain, with SPF, DKIM and DMARC already in place.

It replaces sending only. Everything that calls `wp_mail()` keeps working exactly as it did: WooCommerce, contact forms, membership plugins, your own code.

**What it does**

* Sends every `wp_mail()` message through the Lockally API over HTTPS, not SMTP, so nothing depends on the host's mail setup or on an open outbound port 25.
* Keeps the From address you choose, on a domain you have verified.
* Carries CC, BCC, Reply-To, HTML or plain text, and attachments up to 10 MB each.
* Sends a test message so you can confirm it works before you rely on it.
* Lists recent failures, with the reason, so a message that did not arrive can be explained.

**What it does not do**

* No tracking, no analytics, no phoning home. The plugin contacts Lockally only to send your mail, and only once you have entered a key.
* It does not touch your existing mail until both an API key and a From address are saved. Until then your site sends mail exactly as it did before.

**You need a Lockally account**

This plugin is a client for [Lockally](https://lockally.com), a business email service, and needs an account there and a verified domain. Create an API key in the Lockally console with the `send:write` permission.

Service links: [Lockally](https://lockally.com) · [Terms](https://lockally.com/legal/terms/) · [Privacy](https://lockally.com/legal/privacy/) · [Developer documentation](https://developers.lockally.com)

== External services ==

This plugin connects to the Lockally API, run by Lockally, to send your site's email. That is the plugin's only job, so it cannot work without it.

Nothing is sent until you save both an API key and a From address. After that, each time WordPress sends an email through `wp_mail()`, including the test message on the settings screen, the plugin sends it to the Lockally API at https://api.lockally.com (or to the API address on the settings screen, if Lockally gave you a different one). Each request carries:

* the email itself: the sender (From) address, the recipients (To, CC and BCC), the subject, the body (HTML or plain text), the other headers such as Reply-To, and any attachments;
* your Lockally API key, which Lockally checks before it accepts the message;
* your site's address, in the request's User-Agent header.

The plugin contacts no other service, and sends nothing at any other time.

This service is provided by Lockally: [Terms of Service](https://lockally.com/legal/terms/), [Privacy Policy](https://lockally.com/legal/privacy/).

== Installation ==

1. Install and activate the plugin.
2. Go to **Settings**, then **Lockally Mail**.
3. Paste an API key from the Lockally console. It needs the `send:write` permission.
4. Enter the From address. It has to be on a domain you have verified in Lockally.
5. Send yourself a test message from the same screen.

== Frequently Asked Questions ==

= Do I need SMTP details? =

No. The plugin talks to the Lockally API over HTTPS. Many hosts block outbound SMTP ports; this is unaffected by that.

= What happens if Lockally cannot be reached? =

The message is not sent, and the reason is recorded under Recent failures on the settings screen. WordPress is told the send failed, in the usual way, so any plugin that reports mail problems still reports them.

= Will this send my mail twice? =

No. It takes the message before WordPress hands it to PHPMailer, so only one copy goes out. If another mail plugin got there first, this one stands aside rather than sending a second copy.

= Can I still use a different From address for some mail? =

Yes. The address in a `From:` header on an individual message is respected, as is the `wp_mail_from` filter. The address still has to be on a domain verified in Lockally.

= Can I set the name recipients see? =

The name comes from the sending mailbox in Lockally, not from WordPress. Lockally sends from the address alone, so a display name in a `From:` header or the `wp_mail_from_name` filter is not used. Set the mailbox's display name in the Lockally console.

= Does it store my messages? =

No. The plugin keeps your settings and, for the last twenty failures, the time, the recipients, the subject and the reason it failed. Never the message itself.

== Screenshots ==

1. The settings screen: API key, From address, a test message, and recent failures.

== Changelog ==

= 1.0.0 =
* First release. Sends `wp_mail()` through Lockally, with attachments, CC, BCC, Reply-To, a test message and a record of recent failures.
* Sends the From address alone. Lockally refuses a display name in the sender, so a site with a From name set, or a plugin that adds one, had every message refused. The From name setting is gone; recipients see the sending mailbox's display name.

== Upgrade Notice ==

= 1.0.0 =
First release.
