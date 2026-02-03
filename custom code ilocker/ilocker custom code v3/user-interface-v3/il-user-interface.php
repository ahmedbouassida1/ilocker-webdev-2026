<?php
/**
 * Shortcode: [il-user-interface]
 * Description: Fully merged and unified user interface controller for iLocker Dashboard V3.
 * File Path: custom code ilocker/ilocker custom code v3/user-interface-v3/il-user-interface.php
 */

if (!defined('ABSPATH')) exit;

// Register Shortcode
add_shortcode('il-user-interface', 'il_user_interface_render');

/**
 * Media Library SVG lookup (by filename) with transient caching.
 * Returns an attachment URL or empty string.
 */
function il_user_interface_svg_url_by_filename($filename) {
    $filename = is_string($filename) ? trim($filename) : '';
    if ($filename === '') {
        return '';
    }

    static $runtime_cache = [];
    if (isset($runtime_cache[$filename])) {
        return $runtime_cache[$filename];
    }

    $cache_key = 'il_ui_v3_svg_' . md5($filename);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        $runtime_cache[$filename] = is_string($cached) ? $cached : '';
        return $runtime_cache[$filename];
    }

    $url = '';
    $q = new WP_Query([
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => 1,
        'post_mime_type' => 'image/svg+xml',
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => '_wp_attached_file',
                'value'   => $filename,
                'compare' => 'LIKE',
            ],
        ],
    ]);

    if (!empty($q->posts[0])) {
        $url = wp_get_attachment_url((int) $q->posts[0]);
        $url = is_string($url) ? $url : '';
    }

    set_transient($cache_key, $url, DAY_IN_SECONDS);
    $runtime_cache[$filename] = $url;
    return $url;
}

/** Try multiple SVG filenames and return first URL found. */
function il_user_interface_svg_url_by_filenames($filenames) {
    if (!is_array($filenames)) {
        return '';
    }
    foreach ($filenames as $filename) {
        $url = il_user_interface_svg_url_by_filename($filename);
        if (!empty($url)) {
            return $url;
        }
    }
    return '';
}

function il_user_interface_assets_base_url() {
    $sub_path = '/' . rawurlencode('custom code ilocker') . '/' . rawurlencode('ilocker custom code v3') . '/user-interface-v3';

    $stylesheet_assets_dir = get_stylesheet_directory() . '/custom code ilocker/ilocker custom code v3/user-interface-v3';
    if (is_dir($stylesheet_assets_dir)) {
        return get_stylesheet_directory_uri() . $sub_path;
    }

    return get_template_directory_uri() . $sub_path;
}

function il_user_interface_enqueue_assets() {
    $base_url = il_user_interface_assets_base_url();

    $base_dir = get_stylesheet_directory() . '/custom code ilocker/ilocker custom code v3/user-interface-v3';
    if (!is_dir($base_dir)) {
        $base_dir = get_template_directory() . '/custom code ilocker/ilocker custom code v3/user-interface-v3';
    }
    $css_path = $base_dir . '/il-user-interface.css';
    $js_path  = $base_dir . '/il-user-interface.js';
    $css_ver  = file_exists($css_path) ? (string) filemtime($css_path) : '1.1.3';
    $js_ver   = file_exists($js_path) ? (string) filemtime($js_path) : '1.1.5';

    wp_enqueue_style('il-user-interface-css', $base_url . '/il-user-interface.css', [], $css_ver);
    wp_enqueue_script('il-user-interface-js', $base_url . '/il-user-interface.js', ['jquery'], $js_ver, true);

    wp_localize_script('il-user-interface-js', 'IL_UI_V3', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('il_ui_v3_nav'),
    ]);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        wp_add_inline_script('il-user-interface-js', 'console.log("il-user-interface.js loaded");', 'after');
    }
}

add_action('wp_ajax_il_ui_v3_nav', function() {
    check_ajax_referer('il_ui_v3_nav', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'not_logged_in'], 401);
    }

    $allowed_views = ['dashboard', 'orders', 'addresses', 'details', 'downloads', 'subscriptions'];
    $view = isset($_POST['view']) ? sanitize_key(wp_unslash($_POST['view'])) : 'dashboard';
    if (!in_array($view, $allowed_views, true)) {
        $view = 'dashboard';
    }

    // Support deep-links for orders/addresses.
    $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
    $il_edit  = isset($_POST['il_edit']) ? sanitize_key(wp_unslash($_POST['il_edit'])) : '';
    if (!in_array($il_edit, ['', 'billing', 'shipping'], true)) {
        $il_edit = '';
    }

    // Temporarily map into $_GET for existing renderers.
    $original_get = $_GET;
    $_GET['il_view'] = $view;
    if ($order_id) {
        $_GET['order_id'] = $order_id;
    }
    if ($il_edit) {
        $_GET['il_edit'] = $il_edit;
    }

    ob_start();
    switch ($view) {
        case 'orders':
            il_user_interface_render_orders();
            break;
        case 'addresses':
            il_user_interface_render_addresses();
            break;
        case 'details':
            il_user_interface_render_details();
            break;
        case 'downloads':
            il_user_interface_render_downloads();
            break;
        case 'subscriptions':
            il_user_interface_render_subscriptions();
            break;
        default:
            il_user_interface_render_dashboard();
            break;
    }
    $html = ob_get_clean();

    $_GET = $original_get;

    wp_send_json_success([
        'html'  => $html,
        'view'  => $view,
    ]);
});

add_action('wp_enqueue_scripts', function() {
    if (!is_singular()) {
        return;
    }
    $post = get_post();
    if (!$post || empty($post->post_content)) {
        // Elementor pages can store content in post meta; keep going.
    }

    $has_shortcode = false;
    if ($post && !empty($post->post_content) && (
        has_shortcode($post->post_content, 'il-user-interface')
        || has_shortcode($post->post_content, 'ilocker_user_account')
    )) {
        $has_shortcode = true;
    }

    // Elementor stores layout JSON in _elementor_data (shortcodes appear as plain strings inside).
    if (!$has_shortcode && $post) {
        $elementor_data = get_post_meta($post->ID, '_elementor_data', true);
        if (
            is_string($elementor_data)
            && (
                stripos($elementor_data, 'il-user-interface') !== false
                || stripos($elementor_data, 'ilocker_user_account') !== false
            )
        ) {
            $has_shortcode = true;
        }
    }

    if ($has_shortcode) {
        il_user_interface_enqueue_assets();
    }
});

add_action('wp_footer', function() {
    // Fallback: if optimization/Elementor prevents the external JS from running,
    // keep sidebar tabs working via a tiny vanilla JS handler.
    ?>
    <script>
    (function(){
        if (window.__ilUIV3Loaded) return;
        if (window.__ilUIV3FooterFallbackBound) return;
        var wrapper = document.getElementById('il-v3-wrapper');
        if (!wrapper) return;
        window.__ilUIV3FooterFallbackBound = true;

        function onClick(e){
            var a = e.target && e.target.closest ? e.target.closest('#il-v3-wrapper .il-v3-sidebar a.il-v3-nav-item:not(.logout)') : null;
            if (!a) return;
			if (e.stopImmediatePropagation) e.stopImmediatePropagation();
			e.stopPropagation();
            e.preventDefault();
            var url = a.getAttribute('href');
            if (!url) return;

            var sidebarLinks = document.querySelectorAll('#il-v3-wrapper .il-v3-sidebar .il-v3-nav-item');
            for (var i=0;i<sidebarLinks.length;i++) sidebarLinks[i].classList.remove('active');
            a.classList.add('active');

            var content = document.getElementById('il-v3-content');
            if (!content) { window.location.href = url; return; }
            content.style.opacity = '0.5';

            fetch(url, { credentials: 'same-origin' })
                .then(function(r){ return r.text(); })
                .then(function(html){
                    var parser = new DOMParser();
                    var doc = parser.parseFromString(html, 'text/html');
                    var newContent = doc.getElementById('il-v3-content');
                    if (!newContent) { content.style.opacity = '1'; return; }
                    content.innerHTML = newContent.innerHTML;
                    content.style.opacity = '1';
                    try { window.history.pushState({path:url}, '', url); } catch(_) {}
                })
                .catch(function(){ content.style.opacity = '1'; });
        }

        function parseViewFromUrl(url){
            try {
                var u = new URL(url, window.location.origin);
                return u.searchParams.get('il_view') || 'dashboard';
            } catch(e) {
                return 'dashboard';
            }
        }

        function setActive(view){
            var links = document.querySelectorAll('#il-v3-wrapper .il-v3-sidebar a.il-v3-nav-item:not(.logout)');
            for (var i=0;i<links.length;i++) links[i].classList.remove('active');
            for (var j=0;j<links.length;j++) {
                var href = links[j].getAttribute('href') || '';
                if (parseViewFromUrl(href) === view) {
                    links[j].classList.add('active');
                    break;
                }
            }
        }

        document.addEventListener('click', onClick, true);
        window.addEventListener('popstate', function(){
            var content = document.getElementById('il-v3-content');
            if (!content) return;
            var view = parseViewFromUrl(window.location.href);
            setActive(view);
            content.style.opacity = '0.5';
            fetch(window.location.href, { credentials: 'same-origin' })
                .then(function(r){ return r.text(); })
                .then(function(html){
                    var parser = new DOMParser();
                    var doc = parser.parseFromString(html, 'text/html');
                    var newContent = doc.getElementById('il-v3-content');
                    if (!newContent) { content.style.opacity = '1'; return; }
                    content.innerHTML = newContent.innerHTML;
                    content.style.opacity = '1';
                })
                .catch(function(){ content.style.opacity = '1'; });
        });
    })();
    </script>
    <?php
}, 50);

/**
 * Main Rendering Function
 */
function il_user_interface_render() {
    
    // 1. SECURITY: Ensure User is Logged In
    if (!is_user_logged_in()) {
        $login_url = wc_get_page_permalink('myaccount');
        return '<div class="il-alert il-alert-info">Veuillez vous <a href="'.$login_url.'">connecter</a> pour accéder à votre tableau de bord.</div>';
    }

    // 2. ASSETS
    // NOTE: Assets MUST be enqueued before wp_head; Elementor pages may not contain the shortcode in post_content.
    // We enqueue via wp_enqueue_scripts above (with Elementor meta detection). Avoid relying on late enqueue here.

    // 3. ROUTING: Determine View
    $default_view = 'dashboard';
    $view = isset($_GET['il_view']) ? sanitize_key($_GET['il_view']) : $default_view;
    
    // Allowed views whitelist (Added downloads, subscriptions)
    $allowed_views = ['dashboard', 'orders', 'addresses', 'details', 'downloads', 'subscriptions'];
    if (!in_array($view, $allowed_views)) {
        $view = $default_view;
    }

    ob_start();
    ?>
    
    <!-- MAIN CONTAINER (Target for AJAX) -->
    <div class="il-v3-container" id="il-v3-wrapper">
        
        <!-- SIDEBAR NAVIGATION -->
        <div class="il-v3-sidebar">
            <?php il_user_interface_render_nav($view); ?>
        </div>

        <!-- MAIN CONTENT AREA -->
        <div class="il-v3-content" id="il-v3-content">
            <?php 
            switch ($view) {
                case 'orders':
                    il_user_interface_render_orders();
                    break;
                case 'addresses':
                    il_user_interface_render_addresses();
                    break;
                case 'details':
                    il_user_interface_render_details();
                    break;
                case 'downloads':
                    il_user_interface_render_downloads();
                    break;
                case 'subscriptions':
                    il_user_interface_render_subscriptions();
                    break;
                default:
                    il_user_interface_render_dashboard();
                    break;
            }
            ?>
        </div>

    </div>

    <?php
    return ob_get_clean();
}


/* ==========================================================================
   VIEW RENDERING FUNCTIONS (Merged from separate files)
   ========================================================================== */

/**
 * VIEW: Navigation Sidebar
 */
function il_user_interface_render_nav($current_view) {
    // FIX: Use current page permalink instead of forcing My Account root
    // This allows the shortcode to live on ANY page without breaking navigation.
    global $post;
    $base_url = get_permalink($post ? $post->ID : 0);
    
    // Helper
    $link = function($v) use ($base_url) { return add_query_arg('il_view', $v, $base_url); };
    $active = function($v) use ($current_view) { return $current_view === $v ? 'active' : ''; };

    // Icon helper: try Media Library SVG by filename(s), else fallback HTML.
    $icon = function($filenames, $fallback_html) {
        $url = il_user_interface_svg_url_by_filenames((array) $filenames);
        if (!empty($url)) {
            return '<img class="il-v3-nav-icon" src="' . esc_url($url) . '" alt="" aria-hidden="true" />';
        }
        return $fallback_html;
    };
    ?>
    <nav>
        <!-- 1. DASHBOARD -->
        <a href="<?php echo $link('dashboard'); ?>" class="il-v3-nav-item <?php echo $active('dashboard'); ?>">
            <?php echo $icon(
                ['dashboard.svg', 'track-your.svg', 'track-your-1.svg'],
                '<svg class="il-v3-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>'
            ); ?>
            Tableau de bord
        </a>

        <!-- 2. ORDERS -->
        <a href="<?php echo $link('orders'); ?>" class="il-v3-nav-item <?php echo $active('orders'); ?>">
            <?php echo $icon(
                ['orders.svg', 'orders-1.svg'],
                '<svg class="il-v3-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>'
            ); ?>
            Commandes
        </a>

        <!-- 3. DOWNLOADS (New) -->
        <a href="<?php echo $link('downloads'); ?>" class="il-v3-nav-item <?php echo $active('downloads'); ?>">
           <?php echo $icon(
                ['downloads.svg', 'downloads-1.svg'],
                '<svg class="il-v3-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>'
            ); ?>
            Téléchargements
        </a>

        <!-- 4. SUBSCRIPTIONS -->
        <a href="<?php echo $link('subscriptions'); ?>" class="il-v3-nav-item <?php echo $active('subscriptions'); ?>">
            <?php echo $icon(
                ['subscriptions.svg', 'subscription.svg', 'discuss.svg'],
                '<svg class="il-v3-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>'
            ); ?>
            Abonnements
        </a>

        <!-- 5. ADDRESSES -->
        <a href="<?php echo $link('addresses'); ?>" class="il-v3-nav-item <?php echo $active('addresses'); ?>">
            <?php echo $icon(
                ['addresses.svg', 'adresse.svg', 'address.svg'],
                '<svg class="il-v3-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>'
            ); ?>
            Adresses
        </a>

        <!-- 6. ACCOUNT DETAILS -->
        <a href="<?php echo $link('details'); ?>" class="il-v3-nav-item <?php echo $active('details'); ?>">
            <?php echo $icon(
                ['details.svg', 'informations.svg', 'account.svg', 'user.svg', 'profile.svg'],
                '<svg class="il-v3-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>'
            ); ?>
            Informations
        </a>

        <!-- LOGOUT -->
        <a href="<?php echo wp_logout_url(home_url()); ?>" class="il-v3-nav-item logout">
            <?php echo $icon(
                ['logout.svg', 'deconnexion.svg', 'signout.svg'],
                '<svg class="il-v3-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>'
            ); ?>
            Déconnexion
        </a>
    </nav>
    <?php
}

/**
 * VIEW: Dashboard (Overview)
 */
function il_user_interface_render_dashboard() {
    $user = wp_get_current_user();
    // Fix Name Display: Prefer First Name > Display Name > Login
    $name = !empty($user->first_name) ? $user->first_name : $user->display_name;
    if (empty($name)) $name = $user->user_login;

    // Use get_permalink() to ensure links stay on same page
    global $post;
    $base_url = get_permalink($post ? $post->ID : 0);
    $url = function($v) use ($base_url) { return add_query_arg('il_view', $v, $base_url); };
    ?>
    <h2 class="il-v3-title il-v3-fade-in">Bonjour, <?php echo esc_html($name); ?> !</h2>
    <div class="il-dashboard-card il-v3-fade-in">
        <p style="font-size: 16px; color: var(--text-secondary, #54547E); line-height: 1.6;">
            Depuis votre tableau de bord iLocker, vous pouvez visualiser vos <a href="<?php echo $url('orders'); ?>" class="il-btn-secondary-link">commandes récentes</a>, 
            gérer vos <a href="<?php echo $url('addresses'); ?>" class="il-btn-secondary-link">adresses de livraison</a> 
            ainsi que modifier votre <a href="<?php echo $url('details'); ?>" class="il-btn-secondary-link">mot de passe et les détails de votre compte</a>.
        </p>
    </div>
    <?php
}

/**
 * VIEW: Orders
 */
function il_user_interface_render_orders() {
    global $post;
    $base_url = get_permalink($post ? $post->ID : 0);
    
    $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
    
    // Support standard WC view-order
    if (!$order_id && isset($_GET['view-order'])) {
        $order_id = absint($_GET['view-order']);
    }

    if ($order_id) {
        // --- SINGLE ORDER ---
        $back_url = add_query_arg('il_view', 'orders', $base_url);
        echo '<a href="' . esc_url($back_url) . '" class="il-btn-secondary-link il-v3-nav-item" style="display:inline-flex; width:auto; border:none; margin-bottom:20px; padding:0;">&larr; Retour aux commandes</a>';
        
        $order = wc_get_order($order_id);

        
        if ($order && $order->get_user_id() === get_current_user_id()) {
            ?>
            <div class="il-dashboard-card il-v3-fade-in">
                <div class="il-header-flex">
                    <h3 style="margin:0;">Commande #<?php echo esc_html($order->get_order_number()); ?></h3>
                    <span class="il-status-badge status-<?php echo esc_attr($order->get_status()); ?>"><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></span>
                </div>
                <p style="color:var(--text-secondary);">Passée le <?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></p>

                <div class="il-table-scroll" style="margin-top:20px;">
                <table class="il-custom-table" style="width:100%;">
                    <thead>
                        <tr style="text-align:left; border-bottom:1px solid #eee;">
                            <th style="padding:10px 0;">Produit</th>
                            <th style="padding:10px 0; text-align:right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order->get_items() as $item): ?>
                        <tr style="border-bottom:1px solid #f5f5f5;">
                            <td style="padding:12px 0;">
                                <?php echo esc_html($item->get_name()); ?> 
                                <span style="color:#999;">x <?php echo esc_html($item->get_quantity()); ?></span>
                            </td>
                            <td style="padding:12px 0; text-align:right;">
                                <?php echo wp_kses_post(wc_price($item->get_total())); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td style="padding:12px 0; font-weight:bold;">Total Commande</td>
                            <td style="padding:12px 0; text-align:right; font-weight:bold; font-size:18px; color:var(--ui-primary);"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
                        </tr>
                    </tfoot>
                </table>
                </div>

                <?php 
                    $actions = wc_get_account_orders_actions( $order );
                    if ( ! empty( $actions ) ) {
                        echo '<div style="margin-top:24px; display:flex; gap:10px;">';
                        foreach ( $actions as $key => $action ) {
                            echo '<a href="' . esc_url( $action['url'] ) . '" class="button ' . sanitize_html_class( $key ) . '">' . esc_html( $action['name'] ) . '</a>';
                        }
                        echo '</div>';
                    }
                ?>
            </div>
            <?php
        } else {
            echo '<div class="il-alert il-alert-error">Commande introuvable ou accès refusé.</div>';
        }
    } else {
        // --- LIST ORDERS ---
        echo '<h2 class="il-v3-title">Mes Commandes</h2>';
        $args = ['customer' => get_current_user_id(), 'limit' => 10, 'paginate' => true, 'page' => (get_query_var('paged')) ? get_query_var('paged') : 1];
        $orders = wc_get_orders($args);

        if (!$orders || empty($orders->orders)) {
            echo '<div class="il-dashboard-card il-v3-fade-in"><p style="margin-bottom:20px;">Vous n\'avez passé aucune commande pour le moment.</p><a href="'. wc_get_page_permalink('shop') .'" class="button">Commencer le shopping</a></div>';
        } else {
            ?>
            <div class="il-dashboard-card il-v3-fade-in" style="padding:0;">
                <div class="il-table-scroll">
                    <table class="il-custom-table" style="width:100%; text-align:left; border-collapse: collapse;">
                        <thead style="background:#f9fafb; border-bottom:1px solid #eee;">
                            <tr><th style="padding:16px;">Commande</th><th style="padding:16px;">Date</th><th style="padding:16px;">État</th><th style="padding:16px;">Total</th><th style="padding:16px;"></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders->orders as $order): 
                                $view_url = add_query_arg(['il_view' => 'orders', 'order_id' => $order->get_id()], $base_url);
                            ?>
                            <tr style="border-bottom:1px solid #f0f0f0;">
                                <td style="padding:16px; font-weight:600;">#<?php echo esc_html($order->get_order_number()); ?></td>
                                <td style="padding:16px; color:#666;"><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></td>
                                <td style="padding:16px;"><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></td>
                                <td style="padding:16px; font-weight:600;"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
                                <td style="padding:16px; text-align:right;"><a href="<?php echo esc_url($view_url); ?>" class="il-btn-outline-small" style="display:inline-block; padding:8px 16px; font-size:12px; width:auto; margin:0;">Voir</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php
        }
    }
}

/**
 * VIEW: Addresses
 */
function il_user_interface_render_addresses() {
    global $post;
    $base_url  = get_permalink($post ? $post->ID : 0);
    $edit_mode = isset($_GET['il_edit']) ? sanitize_key($_GET['il_edit']) : '';

    if ($edit_mode && in_array($edit_mode, ['billing', 'shipping'])) {
        // --- EDIT FORM ---
        $cancel_url = add_query_arg(['il_view' => 'addresses'], $base_url);
        $title_map = ['billing' => 'Facturation', 'shipping' => 'Livraison'];
        $title = $title_map[$edit_mode] ?? 'Adresse';

        ?>
        <div class="il-v3-fade-in">
            <div class="il-header-flex">
                <div><h2 class="il-v3-title" style="margin-bottom:4px;">Modifier : <?php echo $title; ?></h2><p style="color:var(--text-secondary); margin:0;">Mettez à jour vos coordonnées.</p></div>
                <a href="<?php echo esc_url($cancel_url); ?>" class="il-btn-secondary-link" style="width:auto; border:none; display:inline-block;">Annuler</a>
            </div>
            <div class="il-dashboard-card il-form-styled-wrapper">
                <?php WC_Shortcode_My_Account::edit_address($edit_mode); ?>
            </div>
        </div>
        <?php
    } else {
        // --- LIST ADDRESSES ---
        ?>
        <div class="il-v3-fade-in">
            <h2 class="il-v3-title">Mes Adresses</h2>
            <?php if (isset($_GET['il_saved'])): ?>
                <div class="il-alert il-alert-success" style="padding:16px; margin-bottom:24px; background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; border-radius:8px;">Adresse mise à jour avec succès.</div>
            <?php endif; ?>
            
            <div class="il-address-list">
            <?php foreach (['billing'=>'Facturation', 'shipping'=>'Livraison'] as $name => $label): 
                $edit_url = add_query_arg(['il_view' => 'addresses', 'il_edit' => $name], $base_url);
                $address = wc_get_account_formatted_address($name);
                $icon = ($name == 'billing') 
                    ? '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>'
                    : '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>';
            ?>
                <div class="il-addr-row">
                    <div class="il-circle-icon"><?php echo $icon; ?></div>
                    <div class="il-addr-details-col">
                        <h4 class="il-addr-title"><?php echo $label; ?></h4>
                        <div class="il-addr-text"><?php echo $address ? wp_kses_post($address) : '<em>Aucune adresse définie.</em>'; ?></div>
                    </div>
                    <div class="il-addr-action-col">
                        <a href="<?php echo esc_url($edit_url); ?>" class="il-btn-edit-outline" style="width:auto; margin:0;"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>Modifier</a>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}

/**
 * VIEW: Account Details
 */
function il_user_interface_render_details() {
    ?>
    <h2 class="il-v3-title il-v3-fade-in">Informations du compte</h2>
    <?php if (isset($_GET['il_saved'])): ?>
        <div class="il-alert il-alert-success il-v3-fade-in" style="padding:16px; margin-bottom:24px; background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; border-radius:8px;">Votre compte a été mis à jour avec succès.</div>
    <?php endif; ?>
    <div class="il-dashboard-card il-form-styled-wrapper il-v3-fade-in">
        <?php WC_Shortcode_My_Account::edit_account(); ?>
    </div>
    <?php
}

/**
 * VIEW: Downloads
 */
function il_user_interface_render_downloads() {
    echo '<h2 class="il-v3-title il-v3-fade-in">Téléchargements</h2>';

    if (!function_exists('wc_get_customer_available_downloads')) {
        echo '<div class="il-dashboard-card il-v3-fade-in"><p>Téléchargements indisponibles.</p></div>';
        return;
    }

    $downloads = wc_get_customer_available_downloads(get_current_user_id());

    echo '<div class="il-dashboard-card il-v3-fade-in" style="padding:0;">';

    if (!empty($downloads)) {
        echo '<div class="il-table-scroll">';
        echo '<table class="il-custom-table" style="width:100%; border-collapse:collapse;">';
        echo '<thead style="background:#f9fafb; border-bottom:1px solid #eee;">';
        echo '<tr><th style="padding:16px;">Produit</th><th style="padding:16px;">Expire le</th><th style="padding:16px;"></th></tr>';
        echo '</thead><tbody>';

        foreach ($downloads as $download) {
            $product_name  = isset($download['product_name']) ? $download['product_name'] : '';
            $download_url  = isset($download['download_url']) ? $download['download_url'] : '';
            $access_expires = isset($download['access_expires']) ? $download['access_expires'] : '';
            $expires_text  = $access_expires ? date_i18n(get_option('date_format'), strtotime($access_expires)) : 'Jamais';

            echo '<tr style="border-bottom:1px solid #f0f0f0;">';
            echo '<td style="padding:16px; font-weight:600;">' . esc_html($product_name) . '</td>';
            echo '<td style="padding:16px; color:#666;">' . esc_html($expires_text) . '</td>';
            echo '<td style="padding:16px; text-align:right;">';
            echo '<a class="il-btn-standard" href="' . esc_url($download_url) . '">Télécharger</a>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    } else {
        echo '<div style="padding:32px; text-align:center;">';
        echo '<p>Aucun téléchargement disponible.</p>';
        echo '<a href="' . esc_url(wc_get_page_permalink('shop')) . '" class="il-btn-secondary-link">Parcourir la boutique</a>';
        echo '</div>';
    }

    echo '</div>';
}

/**
 * VIEW: Subscriptions
 */
function il_user_interface_render_subscriptions() {
    echo '<h2 class="il-v3-title il-v3-fade-in">Mes Abonnements</h2>';

    if (!function_exists('wcs_get_users_subscriptions')) {
        echo '<div class="il-dashboard-card il-v3-fade-in">';
        echo '<p>Le module abonnements n\'est pas activé sur ce site.</p>';
        echo '</div>';
        return;
    }

    $subscriptions = wcs_get_users_subscriptions(get_current_user_id());

    echo '<div class="il-dashboard-card il-v3-fade-in" style="padding:0;">';

    if (!empty($subscriptions)) {
        echo '<div class="il-table-scroll">';
        echo '<table class="il-custom-table" style="width:100%; border-collapse:collapse;">';
        echo '<thead style="background:#f9fafb; border-bottom:1px solid #eee;">';
        echo '<tr><th style="padding:16px;">ID</th><th style="padding:16px;">Statut</th><th style="padding:16px;">Prochain paiement</th><th style="padding:16px;">Total</th><th style="padding:16px;"></th></tr>';
        echo '</thead><tbody>';

        foreach ($subscriptions as $subscription) {
            if (!is_object($subscription) || !method_exists($subscription, 'get_id')) {
                continue;
            }

            $status = method_exists($subscription, 'get_status') ? $subscription->get_status() : '';
            $status_label = function_exists('wcs_get_subscription_status_name') ? wcs_get_subscription_status_name($status) : $status;
            $next_payment = method_exists($subscription, 'get_date_to_display') ? $subscription->get_date_to_display('next_payment') : '';
            $total = method_exists($subscription, 'get_formatted_order_total') ? $subscription->get_formatted_order_total() : '';
            $view_url = method_exists($subscription, 'get_view_order_url') ? $subscription->get_view_order_url() : '';

            echo '<tr style="border-bottom:1px solid #f0f0f0;">';
            echo '<td style="padding:16px; font-weight:600;">#' . esc_html($subscription->get_order_number()) . '</td>';
            echo '<td style="padding:16px;"><span class="il-status-badge status-' . esc_attr($status) . '">' . esc_html($status_label) . '</span></td>';
            echo '<td style="padding:16px;">' . esc_html($next_payment ? $next_payment : '-') . '</td>';
            echo '<td style="padding:16px; font-weight:600;">' . wp_kses_post($total) . '</td>';
            echo '<td style="padding:16px; text-align:right;">';
            if ($view_url) {
                echo '<a class="il-btn-outline-small" href="' . esc_url($view_url) . '">Voir</a>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    } else {
        echo '<div style="padding:32px;">';
        echo '<p>Vous n\'avez aucun abonnement.</p>';
        echo '</div>';
    }

    echo '</div>';
}


/* ==========================================================================
   HOOKS & FILTERS (Redirects & Setup)
   ========================================================================== */

// Confirm Address Save -> Redirect to unified controller
add_filter('woocommerce_save_address_redirect', function($url, $load_address) {
    $ref = wp_get_referer();
    if (!empty($ref)) {
        $ref = esc_url_raw(remove_query_arg(['il_saved'], $ref));
        return add_query_arg(['il_view' => 'addresses', 'il_saved' => 1], $ref);
    }
    return add_query_arg(['il_view' => 'addresses', 'il_saved' => 1], wc_get_page_permalink('myaccount'));
}, 25, 2);

// Confirm Details Save -> Redirect to unified controller
add_filter('woocommerce_save_account_details_redirect', function($url) {
    $ref = wp_get_referer();
    if (!empty($ref)) {
        $ref = esc_url_raw(remove_query_arg(['il_saved'], $ref));
        return add_query_arg(['il_view' => 'details', 'il_saved' => 1], $ref);
    }
    return add_query_arg(['il_view' => 'details', 'il_saved' => 1], wc_get_page_permalink('myaccount'));
}, 25);

// Auto-Creator for Test Page
add_action('init', function() {
    if (isset($_GET['il_create_test_page']) && current_user_can('administrator')) {
        $slug = 'ilocker-user-interface';
        if (!get_page_by_path($slug)) {
            wp_insert_post([
                'post_title' => 'iLocker User Interface V3',
                'post_name' => $slug,
                'post_content' => '[il-user-interface]',
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_author' => get_current_user_id()
            ]);
            wp_die('Test Page [il-user-interface] Created. <a href="'.site_url('/'.$slug).'">View Page</a>');
        }
    }
});
