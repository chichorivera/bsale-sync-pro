<?php
/**
 * Bsale_Stock_Check
 * Verifica el stock real en Bsale antes de agregar al carrito y antes del checkout.
 *
 * El match se hace por SKU: si el producto WooCommerce tiene SKU y existe una variante
 * con ese código en Bsale, se consulta el stock real. Si no tiene SKU o no existe en
 * Bsale, la operación se permite (fail-open).
 *
 * Cache SKU → variantId : 1 hora  (bsale_vid_{md5(sku)})
 * Cache stock real       : 60 seg  (bsale_stock_{variantId}_{officeId})
 */

defined( 'ABSPATH' ) || exit;

class Bsale_Stock_Check {

    public function __construct() {
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_to_cart' ], 10, 4 );
        add_action( 'woocommerce_check_cart_items',       [ $this, 'validate_cart_items' ] );
    }

    // -------------------------------------------------------------------------
    // Verificación al agregar al carrito
    // -------------------------------------------------------------------------

    public function validate_add_to_cart( bool $passed, int $product_id, int $quantity, int $variation_id = 0 ): bool {
        if ( ! $passed ) return false;

        $settings = get_option( BSALE_SYNC_OPTION, [] );

        if ( empty( $settings['access_token'] ) || empty( $settings['office_id'] ) ) {
            return $passed;
        }

        $lookup_id  = $variation_id > 0 ? $variation_id : $product_id;
        $product    = wc_get_product( $lookup_id );

        if ( ! $product ) return $passed;

        $sku = $product->get_sku();
        if ( ! $sku ) return $passed; // sin SKU: no verificar

        $office_id  = (int) $settings['office_id'];
        $variant_id = $this->get_variant_id_by_sku( $sku );

        if ( ! $variant_id || is_wp_error( $variant_id ) ) return $passed;

        $available = $this->get_stock_cached( $variant_id, $office_id );

        if ( is_wp_error( $available ) ) return $passed;

        if ( $available < $quantity ) {
            wc_add_notice( $this->stock_error_message( $product, $quantity, $available ), 'error' );

            Bsale_Stock_Sync::add_log( sprintf(
                'STOCK_CHECK_FAIL  sku=%s  requested=%d  available=%d  product="%s"',
                $sku,
                $quantity,
                $available,
                $product->get_name()
            ) );

            return false;
        }

        return $passed;
    }

    // -------------------------------------------------------------------------
    // Verificación de todos los ítems antes del checkout
    // -------------------------------------------------------------------------

    public function validate_cart_items(): void {
        $settings = get_option( BSALE_SYNC_OPTION, [] );

        if ( empty( $settings['access_token'] ) || empty( $settings['office_id'] ) ) return;

        $office_id = (int) $settings['office_id'];

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            /** @var WC_Product $product */
            $product  = $cart_item['data'];
            $quantity = (int) $cart_item['quantity'];
            $sku      = $product->get_sku();

            if ( ! $sku ) continue;

            $variant_id = $this->get_variant_id_by_sku( $sku );

            if ( ! $variant_id || is_wp_error( $variant_id ) ) continue;

            $available = $this->get_stock_cached( $variant_id, $office_id );

            if ( is_wp_error( $available ) ) continue;

            if ( $available < $quantity ) {
                wc_add_notice( $this->stock_error_message( $product, $quantity, $available ), 'error' );

                Bsale_Stock_Sync::add_log( sprintf(
                    'STOCK_CHECK_FAIL  sku=%s  requested=%d  available=%d  product="%s"',
                    $sku,
                    $quantity,
                    $available,
                    $product->get_name()
                ) );
            }
        }
    }

    // -------------------------------------------------------------------------
    // SKU → variantId (caché 1 hora)
    // -------------------------------------------------------------------------

    private function get_variant_id_by_sku( string $sku ): int|WP_Error {
        $cache_key = 'bsale_vid_' . md5( $sku );
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return (int) $cached; // 0 = no existe en Bsale
        }

        $api     = new Bsale_API();
        $variant = $api->get_variant_by_sku( $sku );

        if ( is_wp_error( $variant ) ) return $variant;

        $id = $variant ? (int) ( $variant['id'] ?? 0 ) : 0;
        set_transient( $cache_key, $id, HOUR_IN_SECONDS );

        return $id;
    }

    // -------------------------------------------------------------------------
    // Stock real con caché de 60 segundos
    // -------------------------------------------------------------------------

    private function get_stock_cached( int $variant_id, int $office_id ): int|WP_Error {
        $cache_key = "bsale_stock_{$variant_id}_{$office_id}";
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return (int) $cached;
        }

        $api    = new Bsale_API();
        $result = $api->get_stock( $variant_id, $office_id );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $available = 0;
        foreach ( (array) ( $result['items'] ?? [] ) as $item ) {
            $available += (int) ( $item['quantityAvailable'] ?? 0 );
        }

        set_transient( $cache_key, $available, 60 );

        return $available;
    }

    // -------------------------------------------------------------------------
    // Mensaje de error (filtrable)
    // -------------------------------------------------------------------------

    private function stock_error_message( WC_Product $product, int $requested, int $available ): string {
        return (string) apply_filters(
            'bsale_stock_error_message',
            sprintf(
                __( 'El producto "%1$s" no tiene stock suficiente en este momento. Disponible: %2$d.', 'bsale-sync-pro' ),
                $product->get_name(),
                $available
            ),
            $product,
            $requested,
            $available
        );
    }
}
