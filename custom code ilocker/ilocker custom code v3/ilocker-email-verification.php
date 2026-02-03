<?php
/**
 * iLocker Email Verification (New Customers)
 *
 * Flow:
 * - On registration: mark user unverified + send verification email.
 * - On login: block unverified users.
 * - On verification link: verify + redirect to /user-account.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Configurable settings (can be overridden via wp-config.php).
if ( ! defined( 'ILOCKER_VERIFY_EXPIRE' ) ) {
	define( 'ILOCKER_VERIFY_EXPIRE', 24 * HOUR_IN_SECONDS );
}
if ( ! defined( 'ILOCKER_VERIFY_DELAY_MIN_MS' ) ) {
	define( 'ILOCKER_VERIFY_DELAY_MIN_MS', 900 );
}
if ( ! defined( 'ILOCKER_VERIFY_DELAY_MAX_MS' ) ) {
	define( 'ILOCKER_VERIFY_DELAY_MAX_MS', 1800 );
}
if ( ! defined( 'ILOCKER_VERIFY_RATE_LIMIT_MAX' ) ) {
	define( 'ILOCKER_VERIFY_RATE_LIMIT_MAX', 5 );
}
if ( ! defined( 'ILOCKER_VERIFY_RATE_LIMIT_WINDOW' ) ) {
	define( 'ILOCKER_VERIFY_RATE_LIMIT_WINDOW', 15 * MINUTE_IN_SECONDS );
}
if ( ! defined( 'ILOCKER_USER_ACCOUNT_SLUG' ) ) {
	define( 'ILOCKER_USER_ACCOUNT_SLUG', 'user-account' );
}

if ( ! function_exists( 'ilocker_ev_get_ip' ) ) {
	function ilocker_ev_get_ip() {
		$ip = '';
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] )[0] ) );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return $ip;
	}
}

function ilocker_ev_user_account_url() {
	$path = '/' . trim( ILOCKER_USER_ACCOUNT_SLUG, '/' ) . '/';
	return home_url( $path );
}

function ilocker_ev_enabled_since() {
	$since = (int) get_option( 'ilocker_ev_enabled_since', 0 );
	if ( $since <= 0 ) {
		$since = time();
		update_option( 'ilocker_ev_enabled_since', $since, false );
	}
	return $since;
}

function ilocker_ev_add_delay() {
	$min = max( 0, (int) ILOCKER_VERIFY_DELAY_MIN_MS );
	$max = max( $min, (int) ILOCKER_VERIFY_DELAY_MAX_MS );
	$ms  = random_int( $min, $max );
	usleep( $ms * 1000 );
}

function ilocker_ev_rate_limit_key( $suffix ) {
	return 'ilocker_ev_' . md5( $suffix );
}

function ilocker_ev_is_rate_limited( $ip, $identifier ) {
	$ip_key    = ilocker_ev_rate_limit_key( 'ip_' . $ip );
	$user_key  = ilocker_ev_rate_limit_key( 'id_' . strtolower( (string) $identifier ) );
	$ip_count  = (int) get_transient( $ip_key );
	$id_count  = (int) get_transient( $user_key );

	if ( $ip_count >= ILOCKER_VERIFY_RATE_LIMIT_MAX || $id_count >= ILOCKER_VERIFY_RATE_LIMIT_MAX ) {
		return true;
	}

	set_transient( $ip_key, $ip_count + 1, ILOCKER_VERIFY_RATE_LIMIT_WINDOW );
	set_transient( $user_key, $id_count + 1, ILOCKER_VERIFY_RATE_LIMIT_WINDOW );

	return false;
}

function ilocker_ev_hash_token( $token ) {
	$token = (string) $token;
	return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
}

function ilocker_ev_generate_token() {
	try {
		return bin2hex( random_bytes( 32 ) );
	} catch ( Exception $e ) {
		return wp_generate_password( 64, false, false );
	}
}

function ilocker_ev_verify_url( $token, $user_login ) {
	$url = add_query_arg(
		array(
			'action' => 'verify_email',
			'key'    => rawurlencode( (string) $token ),
			'login'  => rawurlencode( (string) $user_login ),
		),
		ilocker_ev_user_account_url()
	);
	return set_url_scheme( $url, 'https' );
}

function ilocker_ev_is_verified( $user_id ) {
	$val = get_user_meta( (int) $user_id, 'ilocker_email_verified', true );
	if ( $val === '' ) {
		// Backwards-compatible:
		// - accounts created before feature activation are treated as verified
		// - accounts created after activation are treated as unverified
		$user = get_user_by( 'id', (int) $user_id );
		if ( ! ( $user instanceof WP_User ) || empty( $user->user_registered ) ) {
			return true;
		}
		$registered_ts = strtotime( $user->user_registered );
		if ( ! $registered_ts ) {
			return true;
		}
		return $registered_ts < ilocker_ev_enabled_since();
	}
	return (string) $val === '1';
}

function ilocker_ev_mark_unverified( $user_id ) {
	update_user_meta( (int) $user_id, 'ilocker_email_verified', '0' );
}

function ilocker_ev_mark_verified( $user_id ) {
	update_user_meta( (int) $user_id, 'ilocker_email_verified', '1' );
	delete_user_meta( (int) $user_id, 'ilocker_email_verify_token_hash' );
	delete_user_meta( (int) $user_id, 'ilocker_email_verify_expires' );
}

function ilocker_ev_set_token( $user_id, $token ) {
	update_user_meta( (int) $user_id, 'ilocker_email_verify_token_hash', ilocker_ev_hash_token( $token ) );
	update_user_meta( (int) $user_id, 'ilocker_email_verify_expires', time() + (int) ILOCKER_VERIFY_EXPIRE );
}

function ilocker_ev_branded_email_subject() {
	return 'Vérifiez votre adresse email — iLocker';
}

function ilocker_ev_branded_email_message( WP_User $user, $token ) {
	$verify_url = ilocker_ev_verify_url( $token, $user->user_login );
	$name       = ! empty( $user->display_name ) ? esc_html( $user->display_name ) : 'Cher client';
	$logo_url   = home_url( '/wp-content/uploads/2024/09/logo.png' );

	$html  = '<div style="font-family:\'DM Sans\',Arial,sans-serif;background-color:#F5F5FA;padding:40px 20px;color:#00001A;">';
	$html .= '<div style="max-width:600px;margin:0 auto;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,0.08);border:1px solid #E6E6EC;">';

	$html .= '<div style="background-color:#EEEEFF;padding:32px 40px;text-align:center;">';
	$html .= '<img src="' . esc_url( $logo_url ) . '" alt="iLocker" style="max-height:40px;width:auto;border:0;display:inline-block;" />';
	$html .= '</div>';

	$html .= '<div style="padding:40px 40px 32px 40px;">';
	$html .= '<h2 style="margin:0 0 24px 0;font-family:\'Unbounded\',Arial,sans-serif;font-size:20px;font-weight:700;color:#00001A;">Vérification de votre email</h2>';
	$html .= '<p style="margin:0 0 16px 0;font-size:16px;line-height:1.6;color:#54547E;">Bonjour <strong>' . $name . '</strong>,</p>';
	$html .= '<p style="margin:0 0 24px 0;font-size:16px;line-height:1.6;color:#54547E;">Merci pour votre inscription. Pour activer votre compte et sécuriser l’accès, veuillez vérifier votre adresse email en cliquant sur le bouton ci-dessous :</p>';

	$html .= '<div style="margin:32px 0;text-align:left;">';
	$html .= '<a href="' . esc_url( $verify_url ) . '" style="display:inline-block;background-color:#0000FC;color:#ffffff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;text-align:center;box-shadow:0 4px 12px rgba(0,0,252,0.15);">Vérifier mon adresse email</a>';
	$html .= '</div>';

	$html .= '<p style="margin:0 0 12px 0;font-size:14px;color:#54547E;line-height:1.5;">Ce lien est temporaire et valide pour une durée de 24 heures.</p>';
	$html .= '<p style="margin:0 0 0 0;font-size:14px;color:#54547E;line-height:1.5;">Si vous n’êtes pas à l’origine de cette inscription, vous pouvez ignorer cet email en toute sécurité.</p>';

	$html .= '<div style="margin-top:32px;padding-top:24px;border-top:1px solid #E6E6EC;font-size:12px;color:#8A8AA7;line-height:1.5;">';
	$html .= 'Lien direct (si le bouton ne fonctionne pas) :<br/><a href="' . esc_url( $verify_url ) . '" style="color:#0000FC;text-decoration:none;word-break:break-all;">' . esc_url( $verify_url ) . '</a>';
	$html .= '</div>';

	$html .= '</div>';

	$html .= '<div style="background-color:#F5F5FA;padding:24px 40px;border-top:1px solid #E6E6EC;text-align:center;font-size:13px;color:#8A8AA7;line-height:1.6;">';
	$html .= '<p style="margin:0 0 12px 0;"><strong>Besoin d\'aide ?</strong> Contactez-nous à <a href="mailto:contact@ilocker.com.tn" style="color:#0000FC;text-decoration:none;font-weight:600;">contact@ilocker.com.tn</a>.</p>';
	$html .= '<p style="margin:0;">&copy; ' . date( 'Y' ) . ' iLocker. Tous droits réservés.</p>';
	$html .= '</div>';

	$html .= '</div>';
	$html .= '</div>';

	return $html;
}

function ilocker_ev_send_verification_email( WP_User $user ) {
	$token   = ilocker_ev_generate_token();
	$subject = ilocker_ev_branded_email_subject();
	$message = ilocker_ev_branded_email_message( $user, $token );
	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	ilocker_ev_mark_unverified( $user->ID );
	ilocker_ev_set_token( $user->ID, $token );

	$sent = wp_mail( $user->user_email, $subject, $message, $headers );
	if ( ! $sent ) {
		set_transient( 'ilocker_ev_last_mail_error', 'mail_failed', 5 * MINUTE_IN_SECONDS );
		return new WP_Error( 'mail_failed', 'Email failed.' );
	}

	return true;
}

/**
 * Block login for unverified users.
 */
add_filter( 'wp_authenticate_user', function ( $user ) {
	if ( ! ( $user instanceof WP_User ) ) {
		return $user;
	}
	if ( user_can( $user, 'manage_options' ) ) {
		return $user;
	}
	if ( ilocker_ev_is_verified( $user->ID ) ) {
		return $user;
	}
	return new WP_Error( 'ilocker_email_unverified', 'Email non vérifié.' );
}, 30 );

/**
 * Optional coverage: send verification email for newly created frontend customers
 * (Woo checkout registration etc.).
 */
add_action( 'user_register', function ( $user_id ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return;
	}
	$user = get_user_by( 'id', (int) $user_id );
	if ( ! ( $user instanceof WP_User ) ) {
		return;
	}
	if ( user_can( $user, 'manage_options' ) ) {
		return;
	}
	if ( ilocker_ev_is_verified( $user->ID ) ) {
		return;
	}
	// If a token already exists, don't spam.
	$existing_hash = get_user_meta( $user->ID, 'ilocker_email_verify_token_hash', true );
	if ( ! empty( $existing_hash ) ) {
		return;
	}
	// Only apply to customers.
	if ( ! in_array( 'customer', (array) $user->roles, true ) ) {
		return;
	}

	// Avoid enumeration/spam by rate limiting.
	$ip = ilocker_ev_get_ip();
	if ( ilocker_ev_is_rate_limited( $ip, $user->user_email ) ) {
		return;
	}

	ilocker_ev_send_verification_email( $user );
}, 20 );

/**
 * Handle verification link: /user-account/?action=verify_email&key=...&login=...
 */
add_action( 'template_redirect', function () {
	if ( empty( $_GET['action'] ) || $_GET['action'] !== 'verify_email' ) {
		return;
	}
	if ( empty( $_GET['key'] ) || empty( $_GET['login'] ) ) {
		return;
	}

	$key   = sanitize_text_field( wp_unslash( $_GET['key'] ) );
	$login = sanitize_text_field( wp_unslash( $_GET['login'] ) );

	$base_url = remove_query_arg( array( 'il_ev_error', 'il_ev_success', 'key', 'login', 'action' ), ilocker_ev_user_account_url() );

	$user = get_user_by( 'login', $login );
	if ( ! $user && strpos( $login, '@' ) !== false ) {
		$user = get_user_by( 'email', $login );
	}

	// Generic delay to avoid probing.
	ilocker_ev_add_delay();

	if ( ! ( $user instanceof WP_User ) ) {
		wp_safe_redirect( add_query_arg( 'il_ev_error', 'invalid', $base_url ) );
		exit;
	}

	if ( ilocker_ev_is_verified( $user->ID ) ) {
		wp_safe_redirect( add_query_arg( 'il_ev_success', 'verified', $base_url ) );
		exit;
	}

	$expires = (int) get_user_meta( $user->ID, 'ilocker_email_verify_expires', true );
	if ( empty( $expires ) || $expires < time() ) {
		wp_safe_redirect( add_query_arg( 'il_ev_error', 'expired', $base_url ) );
		exit;
	}

	$expected = (string) get_user_meta( $user->ID, 'ilocker_email_verify_token_hash', true );
	if ( empty( $expected ) ) {
		wp_safe_redirect( add_query_arg( 'il_ev_error', 'invalid', $base_url ) );
		exit;
	}

	$actual = ilocker_ev_hash_token( $key );
	if ( ! hash_equals( $expected, $actual ) ) {
		wp_safe_redirect( add_query_arg( 'il_ev_error', 'invalid', $base_url ) );
		exit;
	}

	ilocker_ev_mark_verified( $user->ID );
	wp_safe_redirect( add_query_arg( 'il_ev_success', 'verified', $base_url ) );
	exit;
}, 0 );

/**
 * Handle resend verification (uniform responses).
 */
add_action( 'template_redirect', function () {
	if ( ! isset( $_POST['il_ev_resend_submit'] ) ) {
		return;
	}

	global $wp;
	$base_url = remove_query_arg( array( 'il_ev_error', 'il_ev_success' ), home_url( add_query_arg( array(), $wp->request ) ) );

	if ( ! isset( $_POST['il_ev_resend_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['il_ev_resend_nonce'] ) ), 'il_ev_resend_action' ) ) {
		wp_safe_redirect( add_query_arg( 'il_ev_error', 'expired', $base_url ) );
		exit;
	}

	$login_or_email = isset( $_POST['il_ev_login'] ) ? sanitize_text_field( wp_unslash( $_POST['il_ev_login'] ) ) : '';
	$ip            = ilocker_ev_get_ip();

	if ( ilocker_ev_is_rate_limited( $ip, $login_or_email ) ) {
		ilocker_ev_add_delay();
		wp_safe_redirect( add_query_arg( 'il_ev_error', 'rate', $base_url ) );
		exit;
	}

	$user = null;
	if ( $login_or_email !== '' ) {
		$user = get_user_by( 'login', $login_or_email );
		if ( ! $user && strpos( $login_or_email, '@' ) !== false ) {
			$user = get_user_by( 'email', $login_or_email );
		}
	}

	// Uniform response + delay to mitigate enumeration.
	ilocker_ev_add_delay();

	if ( $user instanceof WP_User && ! ilocker_ev_is_verified( $user->ID ) ) {
		// Refresh token and send.
		ilocker_ev_send_verification_email( $user );
	}

	wp_safe_redirect( add_query_arg( 'il_ev_success', 'sent', $base_url ) );
	exit;
}, 10 );
