<?php
/*
Plugin Name: Thready Product Customizer
Plugin URI: https://thready.rs
Description: Custom product builder with print designs for WooCommerce
Version: 1.0
Author: Darko
Author URI: mailto:darko1981@gmail.com
Text Domain: thready-product-customizer
Requires at least: 6.0
Requires PHP: 7.4
Requires Plugins: woocommerce
*/

defined('ABSPATH') || exit;

// Define constants
define('THREADY_PC_VERSION', '1.0');
define('THREADY_PC_PATH', plugin_dir_path(__FILE__));
define('THREADY_PC_URL', plugin_dir_url(__FILE__));
define('THREADY_IMAGE_SIZES', [
    'full'          => null,        // Original size (for zoom)
    'woocommerce'   => [800, 800],  // Gallery size
    'thumbnail'     => [150, 150]   // Small images
]);

// Include core files
require_once THREADY_PC_PATH . 'includes/class-admin-settings.php';
require_once THREADY_PC_PATH . 'includes/class-image-handler.php';
require_once THREADY_PC_PATH . 'includes/class-ajax-handler.php';
require_once THREADY_PC_PATH . 'includes/class-frontend-handler.php';

// Couple Mode (admin toggle)
require_once THREADY_PC_PATH . 'includes/class-couple-mode-admin.php';

// Couple Mode (variation settings UI)
require_once THREADY_PC_PATH . 'includes/class-couple-mode-variations-admin.php';

// Custom Order Request (product-level textarea)
require_once THREADY_PC_PATH . 'includes/class-admin-custom-order-request.php';

// Couple Mode (cart / checkout / order meta)
require_once THREADY_PC_PATH . 'includes/class-couple-mode-cart.php';


// Initialize the plugin
add_action('plugins_loaded', function () {

    // WooCommerce check
    if ( ! class_exists('WooCommerce') ) {
        return;
    }

    Thready_Admin_Settings::init();
    Thready_Image_Handler::init();
    Thready_Ajax_Handler::init();
    Thready_Frontend_Handler::init();

    new Thready_Couple_Mode_Admin();
    Thready_Couple_Mode_Variations_Admin::init();
    Thready_Couple_Mode_Cart::init();

    // Custom Order Request init
    if ( class_exists('Thready_Custom_Order_Request') && method_exists('Thready_Custom_Order_Request', 'init') ) {
        Thready_Custom_Order_Request::init();
    }
});

// Create merged images directory
register_activation_hook(__FILE__, function () {
    $upload_dir = wp_upload_dir();
    $custom_dir = $upload_dir['basedir'] . '/thready-merged';

    if (!file_exists($custom_dir)) {
        wp_mkdir_p($custom_dir);
    }

    // Add security file
    $htaccess = $custom_dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "deny from all\n");
    }

    // Add index.php for security
    $index_php = $custom_dir . '/index.php';
    if (!file_exists($index_php)) {
        file_put_contents($index_php, "<?php\n// Silence is golden\n");
    }
});
