<?php

/**
 * Fix Affiliate Wp stuff,
 * seriously ...!?
 */

function wpme_determine_current_user($user_id){
    if(is_array($_SERVER) && function_exists('affiliate_wp')){
        $auth = false;
        if(isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])){
            $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if(isset($_SERVER['HTTP_AUTHORIZATION'])){
            $auth = $_SERVER['HTTP_AUTHORIZATION'];
        }
        if($auth !== false){
            list(
                $_SERVER['PHP_AUTH_USER'],
                $_SERVER['PHP_AUTH_PW']
             ) = explode(':' , base64_decode(substr($auth, 6)));
            $public_key = $_SERVER['PHP_AUTH_USER'];
            $token      = $_SERVER['PHP_AUTH_PW'];
            // Prevent recursion.
            remove_filter( 'determine_current_user', 'wpme_determine_current_user', 19 );
            $rest = new Affiliate_WP_REST;
            if ( $consumer = $rest->consumers->get_by( 'public_key', $public_key ) ) {
                if ( hash_equals( affwp_auth_hash( $public_key, $consumer->secret_key, false ), $token ) ) {
                    return $consumer->user_id;
                }
            }
        }
    }
    return $user_id;
}

add_filter('determine_current_user', 'wpme_determine_current_user', 19, 1);


// Give me email
register_rest_field( 'user', 'user_email',
    array(
        'get_callback'    => function ($user){
            return $user['email'];
        },
        'update_callback' => null,
        'schema'          => null,
    )
);
register_rest_field( 'user', 'user_name',
    array(
        'get_callback'    => function ($user){
            return $user['username'];
        },
        'update_callback' => null,
        'schema'          => null,
    )
);
register_rest_field( 'user', 'affiliate_id',
    array(
        'get_callback'    => function ($user){
            if(!function_exists('affwp_get_affiliate_id')){
                return null;
            }
            return affwp_get_affiliate_id($user['id']);
        },
        'update_callback' => null,
        'schema'          => null,
    )
);
