<?php
/**
 * Plugin Name:  Bsale Sync Pro
 * Description:  Integración Bsale ↔ WooCommerce: emisión de documentos, sincronización de stock y verificación en tiempo real.
 * Version:      1.6.1
 * Author:       JJRC
 * Text Domain:  bsale-sync-pro
 * Requires PHP: 8.0
 * WC requires at least: 7.0
 */

defined( 'ABSPATH' ) || exit;

define( 'BSALE_SYNC_VERSION', '1.6.1' );
define( 'BSALE_SYNC_FILE',    __FILE__ );
define( 'BSALE_SYNC_DIR',     plugin_dir_path( __FILE__ ) );
define( 'BSALE_SYNC_URL',     plugin_dir_url( __FILE__ ) );
define( 'BSALE_SYNC_OPTION',  'bsale_sync_pro_settings' );

/**
 * Agrega el link "Ajustes" en la lista de plugins.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( array $links ): array {
    $settings = '<a href="' . esc_url( admin_url( 'admin.php?page=bsale-sync-pro' ) ) . '">Ajustes</a>';
    array_unshift( $links, $settings );
    return $links;
} );

/**
 * Aviso si WooCommerce no está activo.
 */
function bsale_sync_wc_missing_notice(): void {
    echo '<div class="notice notice-error"><p>'
        . esc_html__( 'Bsale Sync Pro requiere WooCommerce activo.', 'bsale-sync-pro' )
        . '</p></div>';
}

/**
 * Carga principal del plugin.
 */
function bsale_sync_init(): void {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'bsale_sync_wc_missing_notice' );
        return;
    }

    require_once BSALE_SYNC_DIR . 'includes/class-bsale-api.php';
    require_once BSALE_SYNC_DIR . 'includes/class-bsale-settings.php';
    require_once BSALE_SYNC_DIR . 'includes/class-bsale-documents.php';
    require_once BSALE_SYNC_DIR . 'includes/class-bsale-product-meta.php';
    require_once BSALE_SYNC_DIR . 'includes/class-bsale-stock-sync.php';
    require_once BSALE_SYNC_DIR . 'includes/class-bsale-stock-check.php';
    require_once BSALE_SYNC_DIR . 'includes/class-bsale-order-columns.php';

    new Bsale_Settings();
    new Bsale_Documents();
    new Bsale_Product_Meta();
    new Bsale_Stock_Sync();
    new Bsale_Stock_Check();
    new Bsale_Order_Columns();
}
add_action( 'plugins_loaded', 'bsale_sync_init' );

/**
 * Activación: generar webhook secret si no existe.
 */
register_activation_hook( __FILE__, function (): void {
    $settings = get_option( BSALE_SYNC_OPTION, [] );
    if ( empty( $settings['webhook_secret'] ) ) {
        $settings['webhook_secret'] = wp_generate_password( 40, false );
        update_option( BSALE_SYNC_OPTION, $settings );
    }
} );
