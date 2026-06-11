<?php
/**
 * Bsale_Product_Meta
 * Campo _bsale_variant_id en productos simples y variaciones WooCommerce.
 * Permite vincular cada producto/variación con su counterpart en Bsale.
 */

defined( 'ABSPATH' ) || exit;

class Bsale_Product_Meta {

    public function __construct() {
        // Simple: mostrar campo en tab General
        add_action( 'woocommerce_product_options_sku',                   [ $this, 'render_simple_field' ] );
        add_action( 'woocommerce_process_product_meta',                  [ $this, 'save_simple_field' ] );

        // Variable: mostrar campo en cada variación
        add_action( 'woocommerce_product_after_variable_attributes',     [ $this, 'render_variation_field' ], 10, 3 );
        add_action( 'woocommerce_save_product_variation',                [ $this, 'save_variation_field' ], 10, 2 );
    }

    // -------------------------------------------------------------------------
    // Producto simple
    // -------------------------------------------------------------------------

    public function render_simple_field(): void {
        global $post;

        // No mostrar en productos variables (el campo va en cada variación)
        $product = wc_get_product( $post->ID );
        if ( $product && $product->is_type( 'variable' ) ) return;

        echo '<div class="options_group bsale-product-meta-group">';

        woocommerce_wp_text_input( [
            'id'          => '_bsale_variant_id',
            'label'       => 'Bsale Variant ID',
            'description' => 'ID de la variante en Bsale. Se usa para sincronización de stock y verificaciones.',
            'desc_tip'    => true,
            'type'        => 'number',
            'value'       => (string) get_post_meta( $post->ID, '_bsale_variant_id', true ),
            'custom_attributes' => [
                'min'  => '0',
                'step' => '1',
            ],
        ] );

        echo '</div>';
    }

    public function save_simple_field( int $post_id ): void {
        $value = absint( $_POST['_bsale_variant_id'] ?? 0 );
        update_post_meta( $post_id, '_bsale_variant_id', $value > 0 ? $value : '' );
    }

    // -------------------------------------------------------------------------
    // Variaciones
    // -------------------------------------------------------------------------

    public function render_variation_field( int $loop, array $variation_data, WP_Post $variation ): void {
        woocommerce_wp_text_input( [
            'id'            => "_bsale_variant_id_{$loop}",
            'name'          => "variable_bsale_variant_id[{$loop}]",
            'label'         => 'Bsale Variant ID',
            'description'   => 'ID de variante en Bsale.',
            'desc_tip'      => true,
            'type'          => 'number',
            'value'         => (string) get_post_meta( $variation->ID, '_bsale_variant_id', true ),
            'wrapper_class' => 'form-row form-row-full',
            'custom_attributes' => [
                'min'  => '0',
                'step' => '1',
            ],
        ] );
    }

    public function save_variation_field( int $variation_id, int $loop ): void {
        $value = absint( $_POST['variable_bsale_variant_id'][ $loop ] ?? 0 );
        update_post_meta( $variation_id, '_bsale_variant_id', $value > 0 ? $value : '' );
    }
}
