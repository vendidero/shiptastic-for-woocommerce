=== Shiptastic for WooCommerce ===
Contributors: vendidero, vdwoocommercesupport
Tags: shipping, woocommerce, shipments, rules, woo
Requires at least: 5.4
Tested up to: 6.9
Stable tag: 4.8.6
Requires PHP: 5.6
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Shiptastic for WooCommerce is your all-in-one shipping and fulfillment solution for WooCommerce.

== Description ==

Shiptastic for WooCommerce is your all-in-one shipping and fulfillment solution for WooCommerce covering (partial) shipments, return management & much more.

* *Shipments* - Create (partial) shipments for orders - either automatically or by hand.
* *Returns* - Allow your customers to submit return requests for orders. Review and process requests from within your admin panel.
* *Packaging* - Store your packaging options to allow Shiptastic to pack your customer's cart/order based on your available options.
* *Shipping Rules* - Create complex shipping scenarios and calculate shipping costs based on your packaging options.
* *Shipping Service Providers* - Either use one of our available provider integrations or manually add you shipping service provider.

= Order Fulfillment =

With Shiptastic for WooCommerce you may fulfill your orders right from your WooCommerce backend instead of relying on expensive third-party services.
Create shipments, either automatically or manually, which sync all necessary data (e.g. packaging, weight, dimensions) based on your products and
choose different shipping service providers to create labels. Send notifications to your customers with tracking links and descriptions as soon as a shipment is marked as shipped.

= Handle returns with ease =

Shiptastic for WooCommerce makes accepting returns easier for you and your customers. Allow your customers (guest and registered customers) to create return requests for applicable orders which you may either automatically accept or manually approve.
Notify your customers about return shipments and send return instructions, including return labels, to your customers. Optionally charge a fee for a return which will be automatically deducted from the refund created to the return shipment.

= UPS® integration =

Shiptastic comes with a ready-made [integration for UPS](https://wordpress.org/plugins/shiptastic-integration-for-ups). Navigate to WooCommerce > Settings > Shiptastic > Shipping Service Providers and install UPS to create labels for Shipments & Returns right from your dashboard and provide your customers with an easy way to select UPS Access Point™ delivery from within your checkout.

= DHL & Deutsche Post integration =

Business customers from Germany benefit from our [integration for DHL & Deutsche Post](https://wordpress.org/plugins/shiptastic-integration-for-dhl). Navigate to WooCommerce > Settings > Shiptastic > Shipping Service Providers and install DHL to create labels for Shipments & Returns right from your dashboard and provide your customers with an easy way to select Packstation/Postfiliale/Paketshop delivery from within your checkout.

== Frequently Asked Questions ==

= Where can I find the docs? =
Find the [docs](https://vendidero.com/doc/shiptastic/home-shiptastic) on our website.

= How to accept return requests from guests? =
Make sure that you've created a page with the [shiptastic_return_request_form] shortcode. This shortcode will provide
customers (guests, registered customers) with a form to submit a return requests to an order applicable. Also make sure that the
shipping service provider linked to the order/shipment has the return options enabled.

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
3. Order Fulfillment
4. Customer return requests
5. Pickup location delivery
6. Manage returns

== Changelog ==
= 4.8.6 =
* Improvement: Do not fire order shipped actions when order is in bad state, e.g. failed, cancelled
* Fix: Bundle weight calculation for assembled bundles
* Fix: Order status UI update when saving shipments

= 4.8.5 =
* Improvement: Woo 10.4 wc_enqueue_js replacement
* Fix: Fallback compatibility for wp_is_valid_utf8

= 4.8.4 =
* Improvement: Store alternate billing address in shipment
* Improvement: Use billing address as default return shipper address
* Improvement: WooCommerce Shipment Tracking compatibility
* Fix: Default shipping provider list + tracking url

= 4.8.3 =
* Improvement: Add optional shipping weight
* Improvement: Add US customs MID-code

= 4.8.2 =
* Improvement: Add bridging to Woo bundled shipping provider list to improve defaults
* Fix: Address splitter edge cases

= 4.8.1 =
* Fix: Pickup location select for existing customers
* Fix: Reset shipper return address in case not used

= 4.8.0 =
* Improvement: WPML (Email) compatibility
* Improvement: Do not remove local pickup for separately shipped products
* Improvement: Extend shipment data schema to allow manually supplying tracking URL, instructions and provider title
* Improvement: Parse Sendcloud order notes and update shipment(s)
* Improvement: Persist the order return status just like the shipping status

= 4.7.1 =
* Fix: Fallback to default shipping provider for orders

= 4.7.0 =
* New: Support return costs
* New: Create refunds based on returns
* New: Support multiple shipping packages (cart, checkout)
* New: Allow certain products to be shipped separately via a certain provider
* Improvement: Prevent multiple validation events from triggering while saving the order
* Improvement: Heuristic to determine whether house number is stored in address_2
* Improvement: Encode API body args by converting HTML special chars to utf-8 first
* Fix: Bundle container weight

= 4.6.0 =
* New: Setup wizard
* Improvement: Default shipping service provider handling

= 4.5.5 =
* Fix: Remote status tracking
* Fix: Standalone translation

= 4.5.4 =
* Fix: Shipment tracking URL in admin view

= 4.5.3 =
* Fix: Status transitions for ready-for-shipping status
* Improvement: Add tracking link for latest shipment to my account order table

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