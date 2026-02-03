<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'ilk_custom_account_icon_shortcode' ) ) {
    function ilk_custom_account_icon_shortcode() {
        // 1. Définition dynamique de l'URL de l'icône (portable dev/live)
        $icon_path = '/wp-content/uploads/2024/09/account.svg';
        $icon_url  = get_site_url() . $icon_path;

        // 2. URL cible: page Elementor /user-account
        $user_account_page = get_page_by_path( 'user-account' );
        $account_url       = $user_account_page ? get_permalink( $user_account_page ) : home_url( '/user-account/' );

        // 3. Récupération des données utilisateur
        $current_user = wp_get_current_user();
        $is_logged_in = is_user_logged_in();

        // 4. Construction du HTML
        ob_start(); ?>
        <div class="ilk-account-wrapper <?php echo $is_logged_in ? 'ilk-logged-in' : 'ilk-logged-out'; ?>">
            <a href="<?php echo esc_url( $account_url ); ?>" class="ilk-account-link">
                <div class="ilk-icon-container">
                    <img src="<?php echo esc_url( $icon_url ); ?>" alt="iLocker Account" class="ilk-account-svg">
                    <?php if ( $is_logged_in ) : ?>
                        <span class="ilk-status-dot"></span>
                    <?php endif; ?>
                </div>

                <?php if ( $is_logged_in ) :
                    // Priorité au prénom WooCommerce, sinon identifiant
                    $display_name = ! empty( $current_user->first_name ) ? $current_user->first_name : $current_user->display_name;
                    ?>
                    <span class="ilk-welcome-text">Hi, <?php echo esc_html( $display_name ); ?></span>
                <?php endif; ?>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }
}

add_shortcode( 'ilk-account', 'ilk_custom_account_icon_shortcode' );