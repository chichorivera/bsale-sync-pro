<?php
/**
 * Bsale_Stock_Sync
 * Endpoint REST que recibe webhooks de Bsale y actualiza stock en WooCommerce.
 *
 * URL: POST {site}/wp-json/bsale/v1/stock?secret={webhook_secret}
 *
 * Flujo:
 *   1. Validar secret → responder 200 inmediatamente
 *   2. Programar wp_schedule_single_event (procesamiento async)
 *   3. Cron: GET /v1/stocks/{resourceId} → actualizar stock del producto WC mapeado
 */

defined( 'ABSPATH' ) || exit;

class Bsale_Stock_Sync {

    public function __construct() {
        add_action( 'rest_api_init',                   [ $this, 'register_routes' ] );
        add_action( 'bsale_process_stock_webhook',     [ $this, 'process_webhook' ], 10, 1 );
    }

    // -------------------------------------------------------------------------
    // Endpoint REST
    // -------------------------------------------------------------------------

    public function register_routes(): void {
        register_rest_route( 'bsale/v1', '/stock', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_webhook' ],
            'permission_callback' => [ $this, 'verify_secret' ],
        ] );
    }

    public function verify_secret( WP_REST_Request $request ): bool {
        $settings = get_option( BSALE_SYNC_OPTION, [] );
        $secret   = $settings['webhook_secret'] ?? '';

        if ( empty( $secret ) ) return false;

        return hash_equals( $secret, (string) $request->get_param( 'secret' ) );
    }

    public function handle_webhook( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params() ?? [];

        // Ignorar si no es un evento de stock
        if ( ( $body['topic'] ?? '' ) !== 'stock' || empty( $body['resourceId'] ) ) {
            return new WP_REST_Response( [ 'status' => 'ignored' ], 200 );
        }

        $resource_id = (int) $body['resourceId'];

        // Registrar recepción
        $this->add_log( sprintf(
            'WEBHOOK_RECEIVED  resourceId=%d  action=%s',
            $resource_id,
            sanitize_text_field( $body['action'] ?? 'unknown' )
        ) );

        update_option( 'bsale_last_webhook', time(), false );

        // Procesar en background (WP-Cron)
        wp_schedule_single_event( time(), 'bsale_process_stock_webhook', [ $resource_id ] );
        spawn_cron();

        return new WP_REST_Response( [ 'status' => 'queued' ], 200 );
    }

    // -------------------------------------------------------------------------
    // Procesamiento async (WP-Cron)
    // -------------------------------------------------------------------------

    public function process_webhook( int $resource_id ): void {
        $api      = new Bsale_API();
        $settings = get_option( BSALE_SYNC_OPTION, [] );

        // GET /v1/stocks/{resourceId}.json
        $stock_data = $api->get( "stocks/{$resource_id}.json" );

        if ( is_wp_error( $stock_data ) ) {
            $this->add_log( "STOCK_FETCH_ERROR  resourceId={$resource_id}  error=" . $stock_data->get_error_message() );
            error_log( '[Bsale] Stock fetch error resourceId=' . $resource_id . ': ' . $stock_data->get_error_message() );
            return;
        }

        $variant_id = (int) ( $stock_data['variant']['id'] ?? 0 );
        $qty        = (int) ( $stock_data['quantityAvailable'] ?? 0 );

        if ( ! $variant_id ) {
            $this->add_log( "STOCK_PARSE_ERROR  resourceId={$resource_id}  no variantId in response" );
            return;
        }

        // Buscar producto WC por _bsale_variant_id
        $product = $this->find_product_by_variant_id( $variant_id );

        if ( ! $product ) {
            $this->add_log( "STOCK_NOT_MAPPED  variant_id={$variant_id}  qty={$qty}" );
            error_log( "[Bsale] variantId {$variant_id} no mapeado en WooCommerce." );
            return;
        }

        $prev_qty = (int) $product->get_stock_quantity();
        wc_update_product_stock( $product, $qty );

        $this->add_log( sprintf(
            'STOCK_UPDATED  variant_id=%d  wc_product=%d  stock=%d→%d',
            $variant_id,
            $product->get_id(),
            $prev_qty,
            $qty
        ) );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function find_product_by_variant_id( int $variant_id ): WC_Product|null {
        global $wpdb;

        $post_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_bsale_variant_id' AND meta_value = %d
             LIMIT 1",
            $variant_id
        ) );

        if ( ! $post_id ) return null;

        $product = wc_get_product( $post_id );
        return $product ?: null;
    }

    // -------------------------------------------------------------------------
    // Log de eventos
    // -------------------------------------------------------------------------

    public static function add_log( string $message ): void {
        $log   = get_option( 'bsale_event_log', [] );
        $entry = sprintf( '[%s] %s', wp_date( 'Y-m-d H:i:s' ), $message );

        array_unshift( $log, $entry );

        if ( count( $log ) > 100 ) {
            array_splice( $log, 100 );
        }

        update_option( 'bsale_event_log', $log, false );
    }

    public static function get_log(): array {
        return (array) get_option( 'bsale_event_log', [] );
    }

    public static function clear_log(): void {
        update_option( 'bsale_event_log', [], false );
    }
}
