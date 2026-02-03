<?php
/**
 * Smart account page wrapper.
 *
 * Shortcode: [ilocker_user_account]
 * - Logged in: renders [il-user-interface]
 * - Logged out: renders login/register/forgot-password flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'ilocker_user_account', function () {
	// Logged-in users see the account UI V3.
	if ( is_user_logged_in() ) {
		if ( function_exists( 'ilocker_ev_is_verified' ) && ! ilocker_ev_is_verified( get_current_user_id() ) ) {
			$logout_url = wp_logout_url( function_exists( 'ilocker_ev_user_account_url' ) ? ilocker_ev_user_account_url() : home_url( '/' ) );
			ob_start();
			?>
			<div class="il-auth-shell">
				<div class="il-register-card il-login-mode">
					<?php if ( function_exists( 'ilocker_check_url_alerts' ) ) { echo ilocker_check_url_alerts(); } ?>
					<?php
					if ( function_exists( 'ilocker_get_alert_html' ) ) {
						echo ilocker_get_alert_html( 'Votre compte est créé, mais votre email n’est pas encore vérifié. Veuillez vérifier votre boîte email, ou renvoyer un nouveau lien.', 'info' );
					}
					?>
					<form method="post" action="">
						<div class="il-input-group" style="margin-bottom: 12px;">
							<label>Email</label>
							<input type="email" name="il_ev_login" required placeholder="votre@email.com" value="">
						</div>
						<?php wp_nonce_field( 'il_ev_resend_action', 'il_ev_resend_nonce' ); ?>
						<button type="submit" name="il_ev_resend_submit" class="button alt ast-button il-full-width">RENVOYER L’EMAIL</button>
					</form>
					<div style="margin-top: 14px; text-align:center;">
						<a class="il-btn-secondary-link" href="<?php echo esc_url( $logout_url ); ?>">Se déconnecter</a>
					</div>
				</div>
			</div>
			<?php
			return ob_get_clean();
		}
		return do_shortcode( '[il-user-interface]' );
	}

	// Password reset landing from the email: /set-new-password/?action=rp&key=...&login=...
	if (
		isset( $_GET['action'], $_GET['key'], $_GET['login'] )
		&& $_GET['action'] === 'rp'
		&& $_GET['key'] !== ''
		&& $_GET['login'] !== ''
	) {
		return do_shortcode( '[ilocker_reset_password]' );
	}

	$base_url = get_permalink();
	$auth     = isset( $_GET['il_auth'] ) ? sanitize_key( wp_unslash( $_GET['il_auth'] ) ) : 'login';
	if ( ! in_array( $auth, array( 'login', 'register', 'forgot', 'otp' ), true ) ) {
		$auth = 'login';
	}

	// Provide a base URL for nested shortcodes (login/register) to build internal links.
	$GLOBALS['ilocker_user_account_base_url'] = $base_url;

	$login_url    = remove_query_arg( array( 'il_auth', 'il_otp', 'il_otp_error', 'il_otp_success' ), $base_url );
	$register_url = add_query_arg( 'il_auth', 'register', $login_url );

	$active_login    = ( $auth === 'login' || $auth === 'forgot' || $auth === 'otp' );
	$active_register = ( $auth === 'register' );

	$tabs  = '<nav class="il-auth-tabs" data-il-auth-tabs role="tablist" aria-label="Compte">';
	$tabs .= '<a class="il-auth-tab' . ( $active_login ? ' active' : '' ) . '" data-il-auth-view="login" href="' . esc_url( $login_url ) . '">Connexion</a>';
	$tabs .= '<a class="il-auth-tab' . ( $active_register ? ' active' : '' ) . '" data-il-auth-view="register" href="' . esc_url( $register_url ) . '">Inscription</a>';
	$tabs .= '</nav>';

	if ( $auth === 'register' ) {
		$content = do_shortcode( '[ilocker_register]' );
	} elseif ( $auth === 'forgot' ) {
		$content = do_shortcode( '[ilocker_request_password]' );
	} elseif ( $auth === 'otp' ) {
		$content = do_shortcode( '[ilocker_otp]' );
	} else {
		$content = do_shortcode( '[ilocker_login]' );
	}

	$ajax_url = admin_url( 'admin-ajax.php' );
	$nonce    = wp_create_nonce( 'il_auth_v3_nav' );

	return '<div class="il-auth-shell" data-il-auth-shell data-il-auth-ajax-url="' . esc_attr( $ajax_url ) . '" data-il-auth-nonce="' . esc_attr( $nonce ) . '" data-il-auth-base-url="' . esc_attr( $base_url ) . '">' . $tabs . '<div id="il-auth-content" class="il-auth-content">' . $content . '</div></div>';
} );

add_action( 'wp_ajax_nopriv_il_auth_v3_nav', function () {
	check_ajax_referer( 'il_auth_v3_nav', 'nonce' );

	if ( is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => 'logged_in' ), 400 );
	}

	$allowed = array( 'login', 'register', 'forgot', 'otp' );
	$view    = isset( $_POST['view'] ) ? sanitize_key( wp_unslash( $_POST['view'] ) ) : 'login';
	if ( ! in_array( $view, $allowed, true ) ) {
		$view = 'login';
	}

	$base_url = isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '';
	if ( $base_url ) {
		$GLOBALS['ilocker_user_account_base_url'] = $base_url;
	}

	if ( $view === 'register' ) {
		$html = do_shortcode( '[ilocker_register]' );
	} elseif ( $view === 'forgot' ) {
		$html = do_shortcode( '[ilocker_request_password]' );
	} elseif ( $view === 'otp' ) {
		$html = do_shortcode( '[ilocker_otp]' );
	} else {
		$html = do_shortcode( '[ilocker_login]' );
	}

	wp_send_json_success( array( 'html' => $html, 'view' => $view ) );
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( is_user_logged_in() || ! is_singular() ) {
		return;
	}

	$post = get_post();
	if ( ! $post ) {
		return;
	}

	$has_shortcode = false;
	if ( ! empty( $post->post_content ) && has_shortcode( $post->post_content, 'ilocker_user_account' ) ) {
		$has_shortcode = true;
	}

	if ( ! $has_shortcode ) {
		$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
		if ( is_string( $elementor_data ) && stripos( $elementor_data, 'ilocker_user_account' ) !== false ) {
			$has_shortcode = true;
		}
	}

	if ( ! $has_shortcode ) {
		return;
	}

	$rel_path = 'custom code ilocker/ilocker custom code v3/ilocker-auth-v3.js';
	$js_path  = trailingslashit( get_stylesheet_directory() ) . $rel_path;
	$js_url   = trailingslashit( get_stylesheet_directory_uri() ) . $rel_path;

	// Child theme fallback to Astra parent.
	if ( ! file_exists( $js_path ) ) {
		$js_path = trailingslashit( get_template_directory() ) . $rel_path;
		$js_url  = trailingslashit( get_template_directory_uri() ) . $rel_path;
	}

	if ( file_exists( $js_path ) ) {
		wp_enqueue_script( 'ilocker-auth-v3', $js_url, array(), (string) filemtime( $js_path ), true );
		wp_localize_script(
			'ilocker-auth-v3',
			'IL_AUTH_V3',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'il_auth_v3_nav' ),
			)
		);
	}
} );
