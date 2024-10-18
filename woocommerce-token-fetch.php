<?php
/**
 * Plugin Name: WooCommerce Token Fetch
 * Description: Allows WooCommerce users to fetch a safe token from an external service with a TTL of 30 days and provides API endpoints to manage tokens.
 * Version: 1.1
 * Author: Amir Ahmadabadiha
 */

// Fetch token from external service using the username
function wctg_fetch_token_from_external_service($username) {
    $api_url = "https://example.com/get-token?username=" . urlencode($username); 

    $response = wp_remote_get($api_url);

    if (is_wp_error($response)) {
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['token'])) {
        return $data['token'];
    }

    return false;
}

// Store the token and expiration in user meta
function wctg_store_token($user_id, $token) {
    $hashed_token = hash('sha256', $token);
    $expiration_date = date('Y-m-d H:i:s', strtotime('+30 days'));

    // Store the hashed token and expiration date
    update_user_meta($user_id, 'wctg_token', $hashed_token);
    update_user_meta($user_id, 'wctg_token_expiration', $expiration_date);

    return $expiration_date;
}

// Check if the token is valid
function wctg_is_token_valid($user_id) {
    $expiration = get_user_meta($user_id, 'wctg_token_expiration', true);
    if ($expiration && strtotime($expiration) > time()) {
        return true;
    }
    return false;
}

// Shortcode to display token fetching form on WooCommerce account page
function wctg_token_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>You need to log in to fetch your token.</p>';
    }

    $user_id = get_current_user_id();
    $user_info = get_userdata($user_id);
    $username = $user_info->user_login;

    if (wctg_is_token_valid($user_id)) {
        return '<p>Your current token is valid until: ' . get_user_meta($user_id, 'wctg_token_expiration', true) . '</p>';
    }

    // Fetch token if it's expired or doesn't exist
    if (isset($_POST['fetch_token'])) {
        $new_token = wctg_fetch_token_from_external_service($username);
        if ($new_token) {
            $expiration = wctg_store_token($user_id, $new_token);
            return '<p>Your new token: <strong>' . $new_token . '</strong></p><p>It will expire on ' . $expiration . '.</p>';
        } else {
            return '<p>Failed to fetch token from external service.</p>';
        }
    }

    return '
        <form method="POST">
            <input type="submit" name="fetch_token" value="Fetch New Token" />
        </form>
    ';
}
add_shortcode('wctg_token_generator', 'wctg_token_shortcode');

// Hook the shortcode into WooCommerce My Account page
function wctg_add_token_to_account_page() {
    echo do_shortcode('[wctg_token_generator]');
}
add_action('woocommerce_account_dashboard', 'wctg_add_token_to_account_page');

// Register the API route for checking token status
function wctg_register_api_routes() {
    register_rest_route('wctg/v1', '/check-token/', array(
        'methods'  => 'GET',
        'callback' => 'wctg_check_token_status',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
    ));

    // API route to fetch a new token from the external service
    register_rest_route('wctg/v1', '/fetch-token/', array(
        'methods'  => 'POST',
        'callback' => 'wctg_api_fetch_token',
        'permission_callback' => function () {
            return is_user_logged_in();
        },
    ));
}
add_action('rest_api_init', 'wctg_register_api_routes');

// The callback function for the API route to check token status
function wctg_check_token_status(WP_REST_Request $request) {
    $user_id = get_current_user_id();

    if (wctg_is_token_valid($user_id)) {
        $expiration = get_user_meta($user_id, 'wctg_token_expiration', true);
        return new WP_REST_Response(array(
            'status' => 'valid',
            'expiration' => $expiration
        ), 200);
    } else {
        return new WP_REST_Response(array(
            'status' => 'expired or not found'
        ), 200);
    }
}

// The callback function for the API route to fetch a new token from the external service
function wctg_api_fetch_token(WP_REST_Request $request) {
    $user_id = get_current_user_id();
    $user_info = get_userdata($user_id);
    $username = $user_info->user_login;

    if (wctg_is_token_valid($user_id)) {
        return new WP_REST_Response(array(
            'status' => 'error',
            'message' => 'Token is still valid. You can generate a new one after it expires.'
        ), 403);
    }

    // Fetch a new token from the external service
    $new_token = wctg_fetch_token_from_external_service($username);
    if ($new_token) {
        $expiration = wctg_store_token($user_id, $new_token);
        return new WP_REST_Response(array(
            'status' => 'success',
            'token' => $new_token,
            'expiration' => $expiration
        ), 200);
    } else {
        return new WP_REST_Response(array(
            'status' => 'error',
            'message' => 'Failed to fetch token from external service.'
        ), 500);
    }
}

