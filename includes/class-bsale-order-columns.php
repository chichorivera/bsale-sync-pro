<?php
/**
 * Bsale_Order_Columns
 * Agrega dos columnas al listado de pedidos WooCommerce:
 *
 *  - "Envío"  : badge de estado de despacho, clickeable para cambiar (Por enviar / Enviado / Entregado)
 *  - "Bsale"  : ícono con link al PDF del documento emitido, o indicador de estado/error
 *
 * Compatible con HPOS (woocommerce_page_wc-orders) y legacy (edit.php?post_type=shop_order).
 */

defined( 'ABSPATH' ) || exit;

class Bsale_Order_Columns {

    const SHIPPING_STATUSES = [
        'pending'   => 'Por enviar',
        'shipped'   => 'Enviado',
        'delivered' => 'Entregado',
    ];

    public function __construct() {
        // ---- Columnas HPOS ----
        add_filter( 'woocommerce_shop_order_list_table_columns',       [ $this, 'add_columns' ] );
        add_action( 'woocommerce_shop_order_list_table_custom_column',  [ $this, 'render_column_hpos' ], 10, 2 );

        // ---- Columnas legacy ----
        add_filter( 'manage_shop_order_posts_columns',                  [ $this, 'add_columns' ] );
        add_action( 'manage_shop_order_posts_custom_column',            [ $this, 'render_column_legacy' ], 10, 2 );

        // ---- AJAX ----
        add_action( 'wp_ajax_bsale_update_shipping_status', [ $this, 'ajax_update_shipping_status' ] );

        // ---- Assets ----
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    // -------------------------------------------------------------------------
    // Añadir columnas
    // -------------------------------------------------------------------------

    public function add_columns( array $columns ): array {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'order_status' ) {
                $new['shipping_status'] = 'Envío';
                $new['bsale_document']  = 'Bsale';
            }
        }
        return $new;
    }

    // -------------------------------------------------------------------------
    // Render HPOS (recibe WC_Order)
    // -------------------------------------------------------------------------

    public function render_column_hpos( string $column, WC_Order $order ): void {
        match ( $column ) {
            'shipping_status' => $this->render_shipping_column( $order ),
            'bsale_document'  => $this->render_bsale_column( $order ),
            default           => null,
        };
    }

    // -------------------------------------------------------------------------
    // Render legacy (recibe post_id)
    // -------------------------------------------------------------------------

    public function render_column_legacy( string $column, int $post_id ): void {
        if ( ! in_array( $column, [ 'shipping_status', 'bsale_document' ], true ) ) return;

        $order = wc_get_order( $post_id );
        if ( ! $order ) return;

        match ( $column ) {
            'shipping_status' => $this->render_shipping_column( $order ),
            'bsale_document'  => $this->render_bsale_column( $order ),
            default           => null,
        };
    }

    // -------------------------------------------------------------------------
    // Columna: Estado de envío
    // -------------------------------------------------------------------------

    private function render_shipping_column( WC_Order $order ): void {
        $current = $order->get_meta( '_shipping_status' ) ?: 'pending';
        $label   = self::SHIPPING_STATUSES[ $current ] ?? 'Por enviar';
        $nonce   = wp_create_nonce( 'bsale_shipping_' . $order->get_id() );
        ?>
        <span
            class="bsale-shipping-badge bsale-shipping-<?php echo esc_attr( $current ); ?>"
            data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
            data-current="<?php echo esc_attr( $current ); ?>"
            data-nonce="<?php echo esc_attr( $nonce ); ?>"
            title="Click para cambiar estado de envío"
        ><?php echo esc_html( $label ); ?></span>
        <?php
    }

    // -------------------------------------------------------------------------
    // Columna: Bsale (PDF / estado)
    // -------------------------------------------------------------------------

    private function render_bsale_column( WC_Order $order ): void {
        $doc_id    = $order->get_meta( '_bsale_document_id' );
        $doc_url   = $order->get_meta( '_bsale_document_url' );
        $doc_type  = $order->get_meta( '_bsale_document_type' );
        $doc_error = $order->get_meta( '_bsale_document_error' );

        if ( $doc_id && $doc_url ) {
            // Documento emitido con PDF disponible
            $title = sprintf( '%s #%s', ucfirst( $doc_type ?: 'doc' ), $doc_id );
            ?>
            <a href="<?php echo esc_url( $doc_url ); ?>" target="_blank" rel="noopener"
               class="bsale-col-pdf" title="<?php echo esc_attr( $title ); ?>">
                <span class="dashicons dashicons-media-document"></span>
                <span class="bsale-col-pdf-label"><?php echo esc_html( strtoupper( $doc_type ?: 'doc' ) ); ?></span>
            </a>
            <?php

        } elseif ( $doc_id ) {
            // Emitido pero sin URL de PDF
            $title = sprintf( '%s #%s (sin PDF)', ucfirst( $doc_type ?: 'doc' ), $doc_id );
            ?>
            <span class="bsale-col-emitted" title="<?php echo esc_attr( $title ); ?>">
                <span class="dashicons dashicons-yes-alt"></span>
            </span>
            <?php

        } elseif ( $doc_error ) {
            // Error al emitir
            ?>
            <span class="bsale-col-error" title="<?php echo esc_attr( $doc_error ); ?>">
                <span class="dashicons dashicons-warning"></span>
            </span>
            <?php

        } else {
            // Sin documento
            echo '<span class="bsale-col-pending" title="Sin documento Bsale">—</span>';
        }
    }

    // -------------------------------------------------------------------------
    // AJAX: cambiar estado de envío
    // -------------------------------------------------------------------------

    public function ajax_update_shipping_status(): void {
        $order_id = absint( $_POST['order_id'] ?? 0 );

        check_ajax_referer( 'bsale_shipping_' . $order_id, 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( [ 'message' => 'Sin permisos.' ] );
        }

        $status = sanitize_key( $_POST['status'] ?? '' );

        if ( ! array_key_exists( $status, self::SHIPPING_STATUSES ) ) {
            wp_send_json_error( [ 'message' => 'Estado inválido.' ] );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( [ 'message' => 'Pedido no encontrado.' ] );
        }

        $prev = $order->get_meta( '_shipping_status' ) ?: 'pending';
        $order->update_meta_data( '_shipping_status', $status );
        $order->save();

        $order->add_order_note(
            sprintf(
                'Estado de envío: %s → %s',
                self::SHIPPING_STATUSES[ $prev ] ?? $prev,
                self::SHIPPING_STATUSES[ $status ]
            ),
            false,
            true
        );

        wp_send_json_success( [
            'status' => $status,
            'label'  => self::SHIPPING_STATUSES[ $status ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Assets (solo en listado de pedidos)
    // -------------------------------------------------------------------------

    public function enqueue_assets( string $hook ): void {
        $is_hpos   = $hook === 'woocommerce_page_wc-orders';
        $is_legacy = $hook === 'edit.php' && ( $_GET['post_type'] ?? '' ) === 'shop_order';

        if ( ! $is_hpos && ! $is_legacy ) return;

        wp_enqueue_style(
            'bsale-order-cols',
            BSALE_SYNC_URL . 'assets/css/bsale-admin.css',
            [],
            BSALE_SYNC_VERSION
        );

        wp_enqueue_script(
            'bsale-order-cols',
            BSALE_SYNC_URL . 'assets/js/bsale-admin.js',
            [ 'jquery' ],
            BSALE_SYNC_VERSION,
            true
        );

        wp_localize_script( 'bsale-order-cols', 'bsaleAdmin', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'i18n'     => [],
        ] );
    }
}
