<?php
/**
 * Plugin Name: WooCommerce Token Generator
 * Description: Allows WooCommerce users to generate a safe token with a TTL of 30 days.
 * Version: 1.0
 * Author: Amir Ahmadabadiha
 */

// Add custom user meta for token and token expiration date
function wctg_add_user_token_fields($user_id) {
    if (!get_user_meta($user_id, 'wctg_token', true)) {
        update_user_meta($user_id, 'wctg_token', '');
        update_user_meta($user_id, 'wctg_token_expiration', '');
    }
}
add_action('woocommerce_created_customer', 'wctg_add_user_token_fields');

//Generate a safe token
function wctg_generate_safe_token() {
    return bin2hex(random_bytes(16)); // Generates a 32-character token
}

//hash the token using sha256
function wctg_hash_token($token) {
    return hash('sha256', $token);
}

// Check if the current token is valid or expired
function wctg_is_token_valid($user_id) {
    $expiration = get_user_meta($user_id, 'wctg_token_expiration', true);
    if ($expiration && strtotime($expiration) > time()) {
        return true; // Token is still valid
    }
    return false; // Token is expired or doesn't exist
}

//Shortcode to display token generation form on WooCommerce account page
function wctg_token_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>You need to log in to generate a token.</p>';
    }

    $user_id = get_current_user_id();
    $token = get_user_meta($user_id, 'wctg_token', true);

    if (wctg_is_token_valid($user_id)) {
        return '<p>Your current token is valid until: ' . get_user_meta($user_id, 'wctg_token_expiration', true) . '</p>';
    }

    //If token is expired or doesn't exist, allow user to generate a new one
    if (isset($_POST['generate_token'])) {
        $new_token = wctg_generate_safe_token();
        $hashed_token = wctg_hash_token($new_token);
        $expiration_date = date('Y-m-d H:i:s', strtotime('+30 days'));

        // Store the hashed token and its expiration date
        update_user_meta($user_id, 'wctg_token', $hashed_token);
        update_user_meta($user_id, 'wctg_token_expiration', $expiration_date);

        return '<p>Your new token: <strong>' . $new_token . '</strong></p><p>It will expire on ' . $expiration_date . '.</p>';
    }

    return '
        <form method="POST">
            <input type="submit" name="generate_token" value="Generate New Token" />
        </form>
    ';
}
add_shortcode('wctg_token_generator', 'wctg_token_shortcode');

//Hook the shortcode into WooCommerce My Account page
function wctg_add_token_to_account_page() {
    echo do_shortcode('[wctg_token_generator]');
}
add_action('woocommerce_account_dashboard', 'wctg_add_token_to_account_page');

//------------------------------------------- Register the API route
function wctg_register_api_routes() {
    register_rest_route('wctg/v1', '/check-token/', array(
        'methods'  => 'GET',
        'callback' => 'wctg_check_token_status',
        'permission_callback' => function () {
            return is_user_logged_in(); // Ensure the user is logged in
        },
    ));
}
add_action('rest_api_init', 'wctg_register_api_routes');

// The callback function for the API route
function wctg_check_token_status(WP_REST_Request $request) {
    $user_id = get_current_user_id(); // Get the currently logged-in user ID

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

// Check if token is valid
function wctg_is_token_valid($user_id) {
    $expiration = get_user_meta($user_id, 'wctg_token_expiration', true);
    if ($expiration && strtotime($expiration) > time()) {
        return true; // Token is still valid
    }
    return false; // Token is expired or doesn't exist
}
