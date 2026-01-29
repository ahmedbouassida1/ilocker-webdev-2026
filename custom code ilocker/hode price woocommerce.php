/**
 * iLocker Guest Protection (Final)
 * Target: WPO + Quantity + Cart
 * Style: Utilise le bouton natif du thème ("button")
 */

add_action('wp', function() {
    // Si l'utilisateur n'est PAS connecté et qu'on est sur une page produit
    if (!is_user_logged_in() && is_product()) {
        
        // 1. SUPPRIMER LES ÉLÉMENTS WOOCOMMERCE STANDARDS
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 10);
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
        
        // 2. FORCER LE MASQUAGE (CSS)
        add_action('wp_head', function() {
            ?>
            <style>
                .wpo-options-container, 
                .wpo-wrapper,
                #wpo-container,
                .wpo-field,
                .wpo-totals-container,
                .wpo-total,
                form.cart,
                .quantity,
                .qty { 
                    display: none !important; 
                }
            </style>
            <?php
        });

        // 3. AFFICHER LE MESSAGE D'INVITATION
        add_action('woocommerce_single_product_summary', 'ilocker_render_guest_banner', 30);
    }
    
    // Protection Archives
    if (!is_user_logged_in() && (is_shop() || is_product_category())) {
        remove_action('woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_price', 10);
        remove_action('woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10);
        add_action('woocommerce_after_shop_loop_item_title', function() {
            echo '<span style="font-size:13px; color:var(--text-secondary); font-weight:600; display:block; margin-top:5px;">🔒 Réservé aux membres</span>';
        }, 10);
    }
});

// FONCTION D'AFFICHAGE DE LA BANNIÈRE
function ilocker_render_guest_banner() {
    $login_url = wc_get_page_permalink('myaccount');
    ?>
    <div style="
        background: var(--bg-light); 
        border: 2px solid var(--ui-border); 
        border-radius: var(--radius-lg); 
        padding: 40px; 
        text-align: center; 
        margin-top: 20px;
        clear: both;
        box-shadow: var(--shadow-soft);">
        
   
        <p style="color: var(--text-secondary); margin-bottom: 25px; font-size: 15px; line-height: 1.5;">
            Connectez-vous pour configurer les options de ce produit et voir le prix.
        </p>
        
        <a href="<?php echo esc_url($login_url); ?>" class="button" style="margin-top: 10px;">
            Se connecter
        </a>
        
    </div>
    <?php
}