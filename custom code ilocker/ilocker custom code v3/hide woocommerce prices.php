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

// Avoid double-loading if a legacy snippet is still active somewhere.
if ( defined( 'ILOCKER_HIDE_PRICES_GUESTS_LOADED' ) ) {
	return;
}
define( 'ILOCKER_HIDE_PRICES_GUESTS_LOADED', true );

function ilocker_hide_prices_for_guests_is_enabled() {
	return ( ! is_user_logged_in() ) && ( ! is_admin() );
}

function ilocker_hide_prices_for_guests_login_url() {
	if ( function_exists( 'ilocker_ev_user_account_url' ) ) {
		return ilocker_ev_user_account_url();
	}
	return home_url( '/user-account/' );
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

// Guest protection: WPO options + quantity + cart button on product, plus archive label.
add_action(
	'wp',
	function () {
		if ( ! ilocker_hide_prices_for_guests_is_enabled() ) {
			return;
		}

		// Product page protection.
		if ( function_exists( 'is_product' ) && is_product() ) {
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_price', 10 );
			remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );

			add_action(
				'woocommerce_single_product_summary',
				function () {
					$login_url = ilocker_hide_prices_for_guests_login_url();
					echo '<div class="il-guest-banner">';
					echo '<p>Connectez-vous pour configurer les options de ce produit et voir le prix.</p>';
					echo '<a href="' . esc_url( $login_url ) . '" class="button">Se connecter</a>';
					echo '</div>';
				},
				6
			);
		}

		// Archives protection.
		if ( function_exists( 'is_shop' ) && ( is_shop() || ( function_exists( 'is_product_category' ) && is_product_category() ) ) ) {
			remove_action( 'woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10 );
			remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );

			add_action(
				'woocommerce_after_shop_loop_item_title',
				function () {
					echo '<span class="il-guest-reserved-label">';
					echo '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">';
					echo '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path>';
					echo '</svg>';
					echo '<span>Réservé aux membres</span>';
					echo '</span>';
				},
				10
			);
		}
	},
	20
);

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
				'/* Single product (guests): replace the entire summary column with our widget */',
				'body:not(.logged-in).single-product div.product .summary > :not(.product_title):not(.il-guest-banner),',
				'body:not(.logged-in).single-product div.product .entry-summary > :not(.product_title):not(.il-guest-banner) {',
				'\tdisplay: none !important;',
				'}',
				'body:not(.logged-in).single-product div.product .il-guest-banner {',
				'\tmargin-top: 14px;',
				'}',

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

				'/* Barn2 WPO / add-ons: hide option UI & totals for guests on single product */',
				'body:not(.logged-in).single-product .wpo-options-container,',
				'body:not(.logged-in).single-product .wpo-wrapper,',
				'body:not(.logged-in).single-product #wpo-container,',
				'body:not(.logged-in).single-product [id^="wpo"],',
				'body:not(.logged-in).single-product [id*="wpo"],',
				'body:not(.logged-in).single-product [class^="wpo"],',
				'body:not(.logged-in).single-product [class*=" wpo"],',
				'body:not(.logged-in).single-product .wpo-field,',
				'body:not(.logged-in).single-product .wpo-totals-container,',
				'body:not(.logged-in).single-product .wpo-total,',
				'body:not(.logged-in).single-product .wpo-price,',
				'body:not(.logged-in).single-product .wpo-option-price,',
				'body:not(.logged-in).single-product .wpo-price-adjustment,',
				'body:not(.logged-in).single-product .wcpo-options,',
				'body:not(.logged-in).single-product .wcpo-option,',
				'body:not(.logged-in).single-product [class^="wcpo"],',
				'body:not(.logged-in).single-product [class*=" wcpo"],',
				'body:not(.logged-in).single-product .barn2,',
				'body:not(.logged-in).single-product [class*="barn2"],',
				'body:not(.logged-in).single-product form.cart,',
				'body:not(.logged-in).single-product .quantity,',
				'body:not(.logged-in).single-product .qty {',
				'\tdisplay: none !important;',
				'}',

				'/* Guest banner styling */',
				'body:not(.logged-in) .il-guest-banner {',
				'\tbackground: var(--bg-light, #f9fafb);',
				'\tborder: 2px solid var(--ui-border, #e6e6ec);',
				'\tborder-radius: var(--radius-lg, 20px);',
				'\tpadding: 40px;',
				'\ttext-align: center;',
				'\tmargin-top: 20px;',
				'\tclear: both;',
				'\tbox-shadow: var(--shadow-soft, 0 12px 32px rgba(0,0,0,0.08));',
				'}',
				'body:not(.logged-in) .il-guest-banner p {',
				'\tcolor: var(--text-secondary, #54547e);',
				'\tmargin: 0 0 20px;',
				'\tfont-size: 15px;',
				'\tline-height: 1.5;',
				'}',

				'/* Archive reserved label */',
				'body:not(.logged-in) .il-guest-reserved-label {',
				'\tfont-size: 13px;',
				'\tcolor: var(--text-secondary, #54547e);',
				'\tfont-weight: 600;',
				'\tdisplay: inline-flex;',
				'\talign-items: center;',
				'\tgap: 6px;',
				'\tmargin-top: 5px;',
				'}',
			)
		);

		// Prefer attaching to the iLocker stylesheet when present.
		if ( wp_style_is( 'ilocker-v3-custom', 'enqueued' ) || wp_style_is( 'ilocker-v3-custom', 'registered' ) ) {
			wp_add_inline_style( 'ilocker-v3-custom', $css );
		}

		// Fallback: enqueue a tiny inline-only style handle.
		wp_register_style( 'ilocker-hide-prices', false, array(), null );
		wp_enqueue_style( 'ilocker-hide-prices' );
		wp_add_inline_style( 'ilocker-hide-prices', $css );

		// Guaranteed fallback: print the CSS in <head> even if a cache/minifier drops inline styles.
		add_action(
			'wp_head',
			function () use ( $css ) {
				echo "\n" . '<style id="ilocker-hide-prices-guests">' . $css . '</style>' . "\n";
			},
			99
		);
	},
	25
);

