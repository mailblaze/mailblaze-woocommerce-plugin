=== Mailblaze WooCommerce Integration ===
Stable tag: 1.2.0
License: GPLv2

Integrates WooCommerce with Mailblaze.

== Changelog ==

= 1.2.0 =
* Store the email click id (mbclid) on the order and pass it to visitor linking before checkout clears the session.
* Install updates from GitHub releases of the Mail Blaze WooCommerce plugin.

= 1.1.2 =
* Send a checkout-started event when a shopper begins checkout.
* Send product-view events and visitor-link events after consent, so a browse can be tied to a visitor.
