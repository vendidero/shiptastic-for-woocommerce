=== Shiptastic for WooCommerce ===
Contributors: vendidero, vdwoocommercesupport
Tags: shipping, woocommerce, shipments, rules, woo
Requires at least: 5.4
Tested up to: 6.8
Stable tag: 4.5.2
Requires PHP: 5.6
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Shiptastic for WooCommerce is your all-in-one shipping solution for WooCommerce. From partial shipments to shipping rules, Shiptastic got you covered.

== Description ==

Shiptastic for WooCommerce is your all-in-one shipping solution for WooCommerce. From partial shipments to shipping rules, Shiptastic got you covered.

* *Shipments* - Create (partial) shipments for orders - either automatically or by hand.
* *Returns* - Allow your customers to submit return requests for orders. Review and process requests from within your admin panel.
* *Packaging* - Store your packaging options to allow Shiptastic to pack your customer's cart/order based on your available options.
* *Shipping Rules* - Create complex shipping scenarios and calculate shipping costs based on your packaging options.
* *Shipping Service Providers* - Either use one of our available provider integrations or manually add you shipping service provider.

= UPS® integration =

Shiptastic comes with a ready-made [integration for UPS](https://wordpress.org/plugins/shiptastic-integration-for-ups). Navigate to WooCommerce > Settings > Shiptastic > Shipping Service Providers and install UPS to create labels for Shipments & Returns right from your dashboard and provide your customers with an easy way to select UPS Access Point™ delivery from within your checkout.

== Installation ==

= Minimal Requirements =

* WordPress 4.9 or newer
* WooCommerce 3.9 (newest version recommended)
* PHP Version 5.6 or newer

= Automatic Installation =

We recommend installing Shiptastic for WooCommerce through the WordPress Backend. Please install WooCommerce before installing Shiptastic.

== Screenshots ==

1. Manage shipments
2. Create shipping scenarios
3. Manage returns

== Changelog ==
= 4.5.2 =
* Fix: Prevent infinite loops when triggering order shipping status events

= 4.5.1 =
* Fix: Block-based checkout pickup location customer number

= 4.5.0 =
* New: Support remote tracking for shipments
* New: Introduce new shipment status ready-for-shipping
* Improvement: Persist the order shipping status to improve performance and reliability

= 4.4.0 =
* New: Allow choosing single-use only option for packaging in shipping rules
* New: Allow disabling shipping method based on other shipping provider availability
* Improvement: Backend performance in shipping settings context
* Fix: Bundles error when order item product is not found

= 4.3.11 =
* Improvement: Woo 9.9 support
* Fix: Do not pass retry parameter in body args to prevent API error messages
* Fix: Boxpacker expects the max weight to include empty packaging weight

= 4.3.10 =
* New: Shipping rule condition to target subtotals before discount
* Improvement: Assembled Bundles compatibility
* Improvement: Pickup location modal fallback for themes/pagebuilders that do not fire Woo hooks

= 4.3.9 =
* Improvement: Support WC E-Mail previews
* Improvement: Pass shipping provider to pickup location code
* Improvement: Recalculate weight/dimensions in case return units differ from shipment
* Fix: Check if provider supports pickup locations before querying

= 4.3.8 =
* Improvement: Indicate UPS shipping service provider integration availability

= 4.3.7 =
* Fix: Woo backwards compatibility

= 4.3.6 =
* Fix: Make sure that package shipping classes are unique

= 4.3.5 =
* Improvement: Allow sorting main shipments meta box via drag & drop
* Improvement: Prevent errors when deserializing shipping method data
* Improvement: Allow overriding pickup location replacement fields with empty values
* Improvement: Bump template versions
* Improvement: Do only register shipping methods for enabled providers

= 4.3.4 =
* Improvement: Added new filters to allow adjusting default label services
* Fix: Prevent pickup location error in case of empty cache and missing address data

= 4.3.3 =
* Improvement: Cache pickup location data instead of objects

= 4.3.2 =
* Fix: Table per page option

= 4.3.1 =
* Fix: Potential infinite loop when using with WPML
* Fix: Prevent empty location codes

= 4.3.0 =
* Initial version release