<?php
/**
 * Bsale_API
 * Cliente HTTP para api.bsale.io. Responsabilidad única: comunicarse con la API.
 * Todas las demás clases del plugin usan esta como única puerta de entrada.
 */

defined( 'ABSPATH' ) || exit;

class Bsale_API {

    private string $token;
    private string $base_url = 'https://api.bsale.io/v1/';

    public function __construct( string $token = '' ) {
        if ( empty( $token ) ) {
            $settings = get_option( BSALE_SYNC_OPTION, [] );
            $token    = $settings['access_token'] ?? '';
        }
        $this->token = $token;
    }

    // -------------------------------------------------------------------------
    // Métodos HTTP base
    // -------------------------------------------------------------------------

    public function get( string $endpoint, array $params = [] ): array|WP_Error {
        $url = $this->url( $endpoint );
        if ( ! empty( $params ) ) {
            $url = add_query_arg( $params, $url );
        }

        $response = wp_remote_get( $url, [
            'headers' => $this->headers(),
            'timeout' => 15,
        ] );

        return $this->parse( $response );
    }

    public function post( string $endpoint, array $body ): array|WP_Error {
        $url  = $this->url( $endpoint );
        $args = [
            'headers' => $this->headers(),
            'body'    => wp_json_encode( $body ),
            'timeout' => 15,
        ];

        $response = wp_remote_post( $url, $args );

        // Reintento único ante rate limit (429)
        if ( ! is_wp_error( $response ) && 429 === (int) wp_remote_retrieve_response_code( $response ) ) {
            sleep( 1 );
            $response = wp_remote_post( $url, $args );
        }

        return $this->parse( $response );
    }

    // -------------------------------------------------------------------------
    // Métodos específicos de la API de Bsale
    // -------------------------------------------------------------------------

    public function get_document_types(): array|WP_Error {
        return $this->cached( 'document_types', fn() => $this->get( 'document_types.json', [ 'state' => 0 ] ) );
    }

    public function get_price_lists(): array|WP_Error {
        return $this->cached( 'price_lists', fn() => $this->get( 'price_lists.json', [ 'state' => 0 ] ) );
    }

    public function get_offices(): array|WP_Error {
        return $this->cached( 'offices', fn() => $this->get( 'offices.json', [ 'state' => 0 ] ) );
    }

    public function get_client_by_rut( string $rut ): array|null|WP_Error {
        $result = $this->get( 'clients.json', [ 'code' => $rut ] );
        if ( is_wp_error( $result ) ) return $result;
        return ! empty( $result['items'] ) ? $result['items'][0] : null;
    }

    public function create_client( array $data ): array|WP_Error {
        return $this->post( 'clients.json', $data );
    }

    public function create_document( array $data ): array|WP_Error {
        return $this->post( 'documents.json', $data );
    }

    public function get_stock( int $variant_id, int $office_id ): array|WP_Error {
        return $this->get( 'stocks.json', [
            'variantid' => $variant_id,
            'officeid'  => $office_id,
        ] );
    }

    /**
     * Obtiene el precio de una variante dentro de una lista de precios.
     * Retorna el item con variantValue (neto) y variantValueWithTaxes (con IVA).
     */
    public function get_price_list_detail( int $price_list_id, int $variant_id ): array|null|WP_Error {
        $result = $this->get( "price_lists/{$price_list_id}/details.json", [ 'variantid' => $variant_id ] );
        if ( is_wp_error( $result ) ) return $result;
        return ! empty( $result['items'] ) ? $result['items'][0] : null;
    }

    /**
     * Busca una variante en Bsale por su código (= SKU de WooCommerce).
     * Resultado cacheado 1 hora. Retorna null si no existe en Bsale.
     */
    public function get_variant_by_sku( string $sku ): array|null|WP_Error {
        $result = $this->cached(
            'variant_sku_' . md5( $sku ),
            fn() => $this->get( 'variants.json', [ 'code' => $sku ] )
        );

        if ( is_wp_error( $result ) ) return $result;

        return ! empty( $result['items'] ) ? $result['items'][0] : null;
    }

    /**
     * Obtiene los datos de una variante por su ID en Bsale (para obtener su SKU/code).
     */
    public function get_variant( int $variant_id ): array|WP_Error {
        return $this->get( "variants/{$variant_id}.json" );
    }

    // -------------------------------------------------------------------------
    // Helpers privados
    // -------------------------------------------------------------------------

    private function url( string $endpoint ): string {
        return $this->base_url . ltrim( $endpoint, '/' );
    }

    private function headers(): array {
        return [
            'access_token' => $this->token,
            'Content-Type' => 'application/json',
        ];
    }

    private function parse( array|WP_Error $response ): array|WP_Error {
        if ( is_wp_error( $response ) ) {
            error_log( '[Bsale] HTTP error: ' . $response->get_error_message() );
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true ) ?? [];

        if ( $code >= 400 ) {
            $message = $data['error'] ?? $data['description'] ?? "HTTP $code";
            error_log( "[Bsale] API error $code: $message" );
            return new WP_Error( 'bsale_api_error', $message, [ 'status' => $code ] );
        }

        return $data;
    }

    /**
     * Envuelve un callback en un transient de 1 hora.
     * Solo cachea si la respuesta es exitosa.
     */
    private function cached( string $key, callable $callback ): array|WP_Error {
        $transient = 'bsale_cache_' . $key;
        $cached    = get_transient( $transient );

        if ( false !== $cached ) {
            return $cached;
        }

        $result = $callback();

        if ( ! is_wp_error( $result ) ) {
            set_transient( $transient, $result, HOUR_IN_SECONDS );
        }

        return $result;
    }
}
