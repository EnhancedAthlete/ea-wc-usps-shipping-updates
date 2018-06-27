# EA WC – USPS Shipping Updates

A [WooCommerce](https://woocommerce.com/) plugin written by [Brian Henry](https://github.com/brianhenryie) for [Enhanced Athlete](https://v2.enhancedathlete.com/) to update WooCommerce order status based on USPS delivery status. 

USPS provides an API to query the current status of tracking numbers ([HTML](https://www.usps.com/business/web-tools-apis/track-and-confirm-api.htm) / [PDF](https://www.usps.com/business/web-tools-apis/track-and-confirm.pdf)).

The [WooCommerce Shipment Tracking plugin](https://woocommerce.com/products/shipment-tracking/) ($49) provides a UI and API for adding tracking numbers to orders to later expose to users through order pages in /my-account/ and through email. 

[GitHub user Slince](https://github.com/slince) has built and shared a PHP library for querying (amongst others) the USPS API: [github.com/slince/shipment-tracking](https://github.com/slince/shipment-tracking). \**modded for this plugin*

This project brings together the above to improve the customer experience of our WooCommerce store by sending users shipped, out for delivery and delivered emails, and using an action to another plugin to send push notifications.

This plugin was written after considering [AfterShip](https://www.aftership.com/) but upon examining their plugin's code, seeing that it exports significantly more data than is necessary for just shipping updates.

## Installation

[Download the latest version](https://github.com/EnhancedAthlete/ea-wc-usps-shipping-updates/releases/latest), then upload to your WordPress installation (at `/wp-admin/plugin-install.php?tab=upload`).

Register with [USPS Web Tools API Portal](https://www.usps.com/business/web-tools-apis/welcome.htm).

Configure the required USPS User Id under WooCommerce Settings / Integrations / USPS Shipping Updates, and any other options desired
(at `/wp-admin/admin.php?page=wc-settings&tab=integration&section=ea-wc-usps-shipping-updates-integration`).

The plugin has only been tested on PHP 7.1 with WooCommerce 3.4.3 and WordPress 4.9.

## Operation

### Statuses

Traditionally, an order in WooCommerce goes through the status sequence:

* `wc-pending` – pending payment
* `wc-processing` – payment successful, awaiting fulfillment 
* `wc-completed` – dispatched 

To accommodate the more granular data avaialble from USPS, we have introduced:

* `wc-ea-packed` – fulfilled internally but not yet dropped off to/picked up by USPS
* `wc-ea-in-transit` – being sorted and transported by USPS
* `wc-ea-usps-returned` – delivered to the return address

`wc-completed` is still used for delivered orders.

### Status Transitions

Where previously a staff member set the order status as `wc-completed` once packed and stamped and dispatched, it is now necessary to set it to `wc-ea-packed` (see how below).

From here, the plugin queries the USPS API hourly for updates. 
Every USPS status update fires the action:
`do_action( 'ea_wc_usps_shipping_updates', string $new_status, Slince\ShipmentTracking\Foundation\Shipment $shipment, WC_Order $order );`

The following USPS DeliveryAttributeCode : statuses (meaning) will result in no updates:

* `Pre-Shipment Info Sent to USPS, USPS Awaiting Item`
* 33 : `Shipping Label Created, USPS Awaiting Item`
* 38 : `Label Cancelled` (label was printed but never used)

The USPS API responses for 2500 orders were analysed to determine which statuses indicate the package is in their posessions, thus toggling the change to `wc-ea-in-transit`, the most frequent of twelve being:

* `Accepted at USPS Origin Facility`
* `USPS in possession of item`

The following USPS DeliveryAttributeCode : delivery statuses change the order status to `wc-completed`:

* 01 : `Delivered, In/At Mailbox`
* 02 : `Delivered, Front Door/Porch`
* 03 : `Delivered, Parcel Locker`
* 04 : `Delivered, Left with Individual`
* 05 : `Delivered, Front Desk/Reception`
* 06 : `Delivered, Garage or Other Location at Address`
* 08 : `Delivered, PO Box`
* 09 : `Delivered, Individual Picked Up at Postal Facility`
* 10 : `Delivered, Individual Picked Up at Post Office`
* 11 : `Delivered, Parcel Locker`
* 17 : `Delivered, To Mail Room`
* 19 : `Held at Post Office, Retrieved from full parcel locker`
* 23 : `Delivered, To Agent`

Returned packages are marked `wc-ea-usps-returned` when USPS reports:

* 37 : `Return to Sender Processed`
* 41 : `Delivered, To Original Sender`

Many tracking number statuses never reach a status that is clearly delivered. This is typical for overseas orders. To accommodate this, this plugin (configurably) automatically marks orders as complete when their shipping has not updated in two weeks. Until v1.0, check for orders that don't reach a final status.

### Emails

Three new customer emails are added which can be controlled in the WooCommerce Settings Email tab (at `/wp-admin/admin.php?page=wc-settings&tab=email`)

* `Dispatched order` email is sent when the WooCommerce order status is updated to `wc-ea-in-transit`
* `Order out for delivery` email is sent when the USPS status is updated to `Out for Delivery` (there is no corresponding order status update)
* `Delivered order` email replaces the built-in `Completed order` email sent when the status is set to `wc-completed` by this plugin, leaving the `Completed order` email active for all other cases

These are each based on the built-in `Completed order` email template.

### Schedule

A cron job runs hourly querying for orders with a status of `wc-ea-packed` or `wc-ea-in-transit`, limited to 35, repeating every minute until all orders have been checked, using a WP transient to store the created time of the last checked order, iterating from there.

### Multiple Tracking Numbers

If an order has multiple tracking numbers, the status will change to `wc-ea-in-transit` when _any_ package's tracking number indicates USPS posession, a delivery email will be sent when _each_ is considered delivered, and the order's status will change to `wc-completed` when all tracking numbers indicate delivered (or yet to be picked up, e.g. cancelled).

## Implementation

### Packing complete status

In order for the plugin to check an order for shipping updates, its order status in WooCommerce must be set to `wc-ea-packed`. Options to achieve this are:

1. Edit an individual order and setting its status to _Packing complete_
2. On the _Orders_ list screen, use the _Bulk Actions_ menu to _Change status to packing complete_
4. Use this plugin's `ea_wc_usps_shipping_updates_statuses` filter to add another order status to the list watched
5. Check other related plugins for the options to set the status automatically. e.g. [Stamps.com](http://stamps.com/) _StampscomEndicia_ plugin (at `/wp-admin/admin.php?page=wc-settings&tab=integration&section=stampscomendicia`) allows configuring "Shipped Order Status…" should be `ea-packed`.

### Actions

The plugin fires an action on every status change for an order:
`do_action( 'ea_wc_usps_shipping_updates', string $new_status, Slince\ShipmentTracking\Foundation\Shipment $shipment, WC_Order $order );`

We are using [Delite Push Notifications for WordPress](https://www.delitestudio.com/wordpress/push-notifications-for-wordpress/) plugin to send mobile app notifications.

```
add_action( 'ea_wc_usps_shipping_updates', 'send_shipping_update_notifications', 20, 3 );

function send_shipping_update_notifications( $new_status, $shipment, $order ) {
	if( $new_status == 'Out for Delivery' ) {
		$message = 'Your order is out for delivery';
		pnfw_send_notification( $order->get_user_id(), $message );
	}
}
```

## Rate Limits, Performance

Through experimentation, it appears 35 USPS tracking numbers can be queried per request. 

At 35 per minute, this should allow for (35*60=) 2100 orders to be queried per hour. If the full range of order ids is not checked within the hour, the usual hourly cron job will start anyway, continuing to query at the most recently checked order, thus increasing the rate of checking orders until the queue is cleared, then starting at the beginning the following hour.

No USPS rate-limit has been calculated by us, nor has the suggested figure of 2100 orders been tested. The following error was logged during heavy testing:

`Track error: An error has occurred with the service. Please contact the system administrator, or try again.`

Using this plugin without configuring wp-cron to be run externally to user requests would drastically, detrimentaly slow down your site and negatively impact sales.
 
## Troubleshooting, Support
 
 * WC_Logger
 * GitHub issues
 * No support is promised
 * Pull requests are welcome
 * No security audit has been performed
 
## GDPR

This plugin intends to be GDPR compliant before v1.0. WooCommerce explicitly highlights shipment tracking numbers as an item of personal information.

https://docs.woocommerce.com/document/extensions-gdpr-checklist/


## Aspirational Improvements

### PHP

* Unit tests
* PHP code lint
* Catch API errors – currently if 1 in 35 numbers errors, the rest aren't processed? Maybe more a UI consideration i.e. that and other errors don't bubble up to tell store owners there's a problem, e.g. `Track error: An error has occurred with the service. Please contact the system administrator, or try again.`

### Release

* (11) Release on WordPress repo
* Travis CI

### Core

* (1) GDPR: retrieve/delete meta key
* Optional automatic `wc-processing` -> `wc-ea-packed` status update when tracking number meta added 

```
add_action( 'added_post_meta', array( $this, 'added_post_meta' ), 10, 3 ); 

...

public function added_post_meta( $meta_id, $post_id, $meta_key ) {

// When the shipment tracking meta key is updated
if( $meta_key != "_wc_shipment_tracking_items" ) {
    return;
}
```
* (2) On deactivation: orders with custom statuses should change to completed
* (3) On uninstall: all order meta should be deleted
* On (re/)activation: any order meta present could be used to correctly set the order's status
* Slince should be properly forked and PR'd and Composer configured to use a fork

### Admin UI

#### Settings page

* Check for dependencies in WP Plugins UI, Dashboard and WooCommerce settings
* List who has hooked into the action
* Validate the USPS user id when saving the settings
 
#### Orders List View

* Bulk update status doesn't leave a polite confirmation notice for the admin. Should also add an order note.
* Shipment tracking plugin shows tracking number in orders list UI... should replace with latest data

#### Single Order View

* (10) There's no easy way to modify the Shipment Tracking meta box on the admin order page. Hopefully they'll add an action that can be used. For now, options are to add a separate box or use JavaScript to modify the existing one.
* Order page should show the most recent USPS update inside the shipment tracking plugin's UI and full USPS log elsewhere. (shipment plugin uses templates)

#### Dashboard

*  Dashboard widget showing processing / with USPS / delivered by day - linking to orders page.

### Customer UI

* Customer order UI should show tracking info inline.

```
// Replace View Order screen shipment tracking details with our own (user view)
$wc_shipment_tracking = wc_shipment_tracking();
remove_action( 'woocommerce_view_order', array( $wc_shipment_tracking->actions, 'display_tracking_info' ) );
add_action( 'woocommerce_view_order', array( $this, 'display_tracking_info' ), 5 );

/**
 * Display Shipment info in the frontend (order view/tracking page).
 *
 * @param int $order_id
 */
public function display_tracking_info( $order_id ) {

	$order = wc_get_order( $order_id );

	$wc_shipment_tracking = wc_shipment_tracking();

	$tracking_items = $wc_shipment_tracking->actions->get_tracking_items( $order_id, true );

//		$order->get_meta( self::ORDER_USPS_DETAIL_META_KEY );

	wc_get_template( 'myaccount/view-order.php', array( 'tracking_items' => $tracking_items ), 'ea-wc-usps-shipping-updates', untrailingslashit( plugin_dir_path( dirname( __FILE__ ) ) ) . '/ea-wc-usps-shipping-updates/templates/' );
}
```
* Emails currently just use the default WooCommerce complete email - Emails should be more like Amazon's - Amazon's are distinctly different, ~"your order of 2. product name" in the subject
* Use the delivery times to let users know before checkout what's typical transit time to their state

### Compatability

* WooCommerce CRUD -- used for the most part. maybe not in reactivation.
* Breaks reporting -- reporting plugins looking for specific statuses (e.g. that consider `wc-completed` for revenue) and will overlook `wc-ea-packed`, `wc-ea-in-transit` and `wc-ea-usps-returned` in all their calculations. If these plugins have hooks that could be acknowledged by this plugin, please open an issue or a PR.

### Version History

* (9) Create a version history file 


## Plugin Status

I'm hesitant to call this plugin version 1.0 until someone has published a thesis of the USPS statuses. Is there public documentation giving all DeliveryAttributeCodes and outcomes? 

Until then, anyone using this plugin should regularly check their oldest `wc-processing` and `wc-ea-in-transit` orders to understand what their final statuses mean. And please open an issue to let me know of any incorrect behaviour.

Everytime a USPS DeliveryAttributeCode that isn't comprehensively handled is encountered, it is logged, so please report them too.