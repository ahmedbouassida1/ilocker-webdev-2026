<?php
/**
 * iLocker — Hide WooCommerce prices for guests
 *
 * Hides price display for non-logged-in visitors across product loops,
 * single product pages, cart/checkout totals, and option price fragments.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ilocker_hide_prices_for_guests_is_enabled() {
	return ( ! is_user_logged_in() ) && ( ! is_admin() );
}

function ilocker_hide_prices_for_guests_empty_html( $html ) {
	return ilocker_hide_prices_for_guests_is_enabled() ? '' : $html;
}

// Product prices (shop + single product + variations).
add_filter( 'woocommerce_get_price_html', 'ilocker_hide_prices_for_guests_empty_html', 999 );
add_filter( 'woocommerce_variable_price_html', 'ilocker_hide_prices_for_guests_empty_html', 999 );
add_filter( 'woocommerce_variable_sale_price_html', 'ilocker_hide_prices_for_guests_empty_html', 999 );
add_filter( 'woocommerce_get_variation_price_html', 'ilocker_hide_prices_for_guests_empty_html', 999 );

// Cart / mini-cart / checkout totals.
add_filter( 'woocommerce_cart_item_price', 'ilocker_hide_prices_for_guests_empty_html', 999 );
add_filter( 'woocommerce_cart_item_subtotal', 'ilocker_hide_prices_for_guests_empty_html', 999 );
add_filter( 'woocommerce_cart_subtotal', 'ilocker_hide_prices_for_guests_empty_html', 999 );
add_filter( 'woocommerce_cart_totals_order_total_html', 'ilocker_hide_prices_for_guests_empty_html', 999 );

// Some themes/plugins render price-in-quantity strings in the widget cart.
add_filter(
	'woocommerce_widget_cart_item_quantity',
	function ( $html ) {
		if ( ! ilocker_hide_prices_for_guests_is_enabled() ) {
			return $html;
		}
		// Keep quantity number but strip any embedded price amount.
		$html = preg_replace( '/<span class="woocommerce-Price-amount[^>]*>.*?<\/span>/i', '', (string) $html );
		$html = preg_replace( '/<span class="woocommerce-Price-currencySymbol[^>]*>.*?<\/span>/i', '', (string) $html );
		return $html;
	},
	999
);

// Last-mile: hide remaining amounts via CSS (covers option add-ons too).
add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! ilocker_hide_prices_for_guests_is_enabled() ) {
			return;
		}

		$css = implode(
			"\n",
			array(
				'body:not(.logged-in) .woocommerce-Price-amount,',
				'body:not(.logged-in) .woocommerce-Price-currencySymbol,',
				'body:not(.logged-in) .price,',
				'body:not(.logged-in) .woocommerce-variation-price,',
				'body:not(.logged-in) .wc-block-components-product-price,',
				'body:not(.logged-in) .wc-block-components-totals-item__value,',
				'body:not(.logged-in) .wc-block-mini-cart__amount,',
				'body:not(.logged-in) .wc-block-mini-cart__amount-badge {',
				'\tdisplay: none !important;',
				'}',
			)
		);

		// Prefer attaching to the iLocker stylesheet when present.
		if ( wp_style_is( 'ilocker-v3-custom', 'enqueued' ) || wp_style_is( 'ilocker-v3-custom', 'registered' ) ) {
			wp_add_inline_style( 'ilocker-v3-custom', $css );
			return;
		}

		// Fallback: enqueue a tiny inline-only style handle.
		wp_register_style( 'ilocker-hide-prices', false, array(), null );
		wp_enqueue_style( 'ilocker-hide-prices' );
		wp_add_inline_style( 'ilocker-hide-prices', $css );
	},
	25
);

