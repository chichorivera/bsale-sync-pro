<?php
/**
 * Bsale_Stock_Check
 * Verifica el stock real en Bsale antes de agregar al carrito y antes del checkout.
 *
 * El match se hace por SKU usando GET /stocks.json?code={sku}&officeid={id},
 * que es el endpoint oficial de Bsale para consultar stock directamente por SKU.
 * No se necesita resolver el variantId primero.
 *
 * Si el producto no tiene SKU o la API falla, la operación se permite (fail-open).
 *
 * Cache: bsale_stock_sku_{md5(sku)}_{officeId} — 60 segundos
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

        $lookup_id = $variation_id > 0 ? $variation_id : $product_id;
        $product   = wc_get_product( $lookup_id );

        if ( ! $product ) return $passed;

        $sku = $product->get_sku();
        if ( ! $sku ) return $passed;

        $office_id = (int) $settings['office_id'];
        $available = $this->get_stock_cached( $sku, $office_id );

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

            $available = $this->get_stock_cached( $sku, $office_id );

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
    // Stock por SKU con caché de 60 segundos
    // -------------------------------------------------------------------------

    private function get_stock_cached( string $sku, int $office_id ): int|WP_Error {
        $cache_key = 'bsale_stock_sku_' . md5( $sku ) . '_' . $office_id;
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return (int) $cached;
        }

        $api    = new Bsale_API();
        $result = $api->get( 'stocks.json', [
            'code'     => $sku,
            'officeid' => $office_id,
        ] );

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
