<?php
/**
 * Bsale_Stock_Check
 * Verifica el stock real en Bsale antes de agregar al carrito y antes del checkout.
 *
 * Si el producto no tiene _bsale_variant_id mapeado, o si la API falla, se permite
 * la operación (fail-open) para no bloquear ventas por problemas de integración.
 *
 * Cache: transient bsale_stock_{variantId}_{officeId} con TTL de 60 segundos.
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

    /**
     * @param bool $passed
     * @param int  $product_id   ID del producto padre
     * @param int  $quantity
     * @param int  $variation_id ID de variación (0 si es producto simple)
     */
    public function validate_add_to_cart( bool $passed, int $product_id, int $quantity, int $variation_id = 0 ): bool {
        if ( ! $passed ) return false; // ya falló por otra validación

        $settings = get_option( BSALE_SYNC_OPTION, [] );

        // Sin token o sin bodega configurada: no bloquear
        if ( empty( $settings['access_token'] ) || empty( $settings['office_id'] ) ) {
            return $passed;
        }

        // Para variaciones, buscar el meta en la variación; si no, en el producto padre
        $lookup_id  = $variation_id > 0 ? $variation_id : $product_id;
        $product    = wc_get_product( $lookup_id );

        if ( ! $product ) return $passed;

        $variant_id = (int) $product->get_meta( '_bsale_variant_id' );
        if ( ! $variant_id ) return $passed; // no mapeado: permitir

        $office_id = (int) $settings['office_id'];
        $available = $this->get_stock_cached( $variant_id, $office_id );

        if ( is_wp_error( $available ) ) return $passed; // error de API: no bloquear

        if ( $available < $quantity ) {
            wc_add_notice( $this->stock_error_message( $product, $quantity, $available ), 'error' );

            Bsale_Stock_Sync::add_log( sprintf(
                'STOCK_CHECK_FAIL  variant_id=%d  requested=%d  available=%d  product="%s"',
                $variant_id,
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
            $product    = $cart_item['data'];
            $quantity   = (int) $cart_item['quantity'];
            $variant_id = (int) $product->get_meta( '_bsale_variant_id' );

            if ( ! $variant_id ) continue;

            $available = $this->get_stock_cached( $variant_id, $office_id );

            if ( is_wp_error( $available ) ) continue;

            if ( $available < $quantity ) {
                wc_add_notice( $this->stock_error_message( $product, $quantity, $available ), 'error' );

                Bsale_Stock_Sync::add_log( sprintf(
                    'STOCK_CHECK_FAIL  variant_id=%d  requested=%d  available=%d  product="%s"',
                    $variant_id,
                    $quantity,
                    $available,
                    $product->get_name()
                ) );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Stock con caché de 60 segundos
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

        // Sumar quantityAvailable de todos los ítems retornados
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
                /* translators: 1: product name, 2: available quantity */
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
