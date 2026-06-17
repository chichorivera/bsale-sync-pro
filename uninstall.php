<?php
/**
 * Bsale Sync Pro — Desinstalación
 *
 * Se ejecuta cuando el usuario elimina el plugin desde el panel de WordPress.
 * Limpia TODA la información del plugin: opciones, transients, cron y metas de pedidos.
 *
 * Los metas de productos (_bsale_variant_id) se conservan opcionalmente:
 * descomenta el bloque al final si quieres borrarlos también.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// ---- Opciones ---------------------------------------------------------------

delete_option( 'bsale_sync_pro_settings' );
delete_option( 'bsale_event_log' );
delete_option( 'bsale_last_webhook' );

// ---- Transients (caché API + stock) ----------------------------------------

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bsale_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_bsale_%'" );

// ---- Cron ------------------------------------------------------------------

wp_clear_scheduled_hook( 'bsale_process_stock_webhook' );
wp_clear_scheduled_hook( 'bsale_process_price_webhook' );

// ---- Meta de pedidos (legacy postmeta + HPOS) ------------------------------

$order_meta_keys = [
    '_bsale_document_id',
    '_bsale_document_url',
    '_bsale_document_type',
    '_bsale_document_error',
    '_bsale_emission_date',
    '_shipping_status',
];

// Legacy orders (post-based)
foreach ( $order_meta_keys as $key ) {
    $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $key ], [ '%s' ] );
}

// HPOS orders (WooCommerce 7.1+)
$hpos_table = $wpdb->prefix . 'wc_orders_meta';
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
    foreach ( $order_meta_keys as $key ) {
        $wpdb->delete( $hpos_table, [ 'meta_key' => $key ], [ '%s' ] );
    }
}

// ---- Transients adicionales (SKU → variantId, caché de variantes) ----------
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bsale_vid_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_bsale_vid_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bsale_variant_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_bsale_variant_%'" );
