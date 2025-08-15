<?php
/**
 * We're jumping on a band wagon here, and doing
 * it the way of "functional programming" may god save us.
 *
 * -- code start at 3300
 */

/**
 * @return mixed
 * @throws \Error
 */
function wpme_api_get_base_url(){
    $settings = get_option('WPME_AFFI');
    if(isset($settings['wpmeAffDomain'])){
        return rtrim($settings['wpmeAffDomain'], '/') . '/';
    }
    throw new Exception(
        'The parent domain is not specified.',
        3301
    );
}

/**
 * @param string $type
 * @return array
 * @throws \Error
 */
function wpme_api_get_api_headers_for($type = 'aff'){
    $settings = get_option('WPME_AFFI');
    if(!isset($settings['wpmeAffApiPublicKey'])
        ||
        !isset($settings['wpmeAffApiToken'])
    ){
        throw new Exception(
            'Headers for Affilaites can\'t be set, because of missing Public Key or Token',
            3302
        );
    }
    return array(
        'headers' => array(
            'Authorization' => 'Basic ' . base64_encode($settings['wpmeAffApiPublicKey'] . ':' . $settings['wpmeAffApiToken']),
        )
    );
}

/**
 * @param array $array
 * @param array $cond
 * @param bool $first
 * @return (array|object)
 */
function __where($array = [], array $cond = [], $first = true){
    if(!is_array($array)){
      return [];
    }
    $result = null;
    foreach ($array as $arrItem) {
        $arrItem = (array)$arrItem;
        foreach ($cond as $condK => $condV) {
            if (!isset($arrItem[$condK]) || $arrItem[$condK] !== $condV) {
                continue 2;
            }
        }
        $result = (object)$arrItem;
    }
    return $result;
}

/**
 * @param $email
 * @return \Exception|null|object
 * @throws \Error
 */
function wpme_api_get_user_by_email($email){
    try {
        $domain = wpme_api_get_base_url();
        $args = wpme_api_get_api_headers_for('wp');
        $result = wp_remote_get($domain . 'wp-json/wp/v2/users?per_page=100&search=' . urlencode($email), $args);
        wpme_check_request(
          $result,
          'Error gettgin a user by email',
          3114
        );
        wpme_simple_log('Result of search for ' . urlencode($email));
        wpme_simple_log('Result of search ' . var_export($result, true));
        if($result['response']['code'] === 200){
            $body = json_decode($result['body']);
            return __where($body,
                ['user_email' => $email]
            );
        }
        return null;
    } catch (Exception $e){
        return null;
    }
}

/**
 * @param $id
 * @param $newUsername
 * @return mixed
 * @throws \Error
 */
function wpme_change_existing_username($id, $newUsername){
    global $wpdb;
    // Easy, kill of without this
    if(!is_object($wpdb)){
        throw new Exception('WPDB is not defined', 3304);
    }
    // user_login
    // user_nicename
    return $wpdb->update(
        $wpdb->users,
        array(
            'user_login' => $newUsername
        ),
        array(
            'ID' => $id
        )
    );
}

/**
 * @param $user_from_api
 * @param $affilaite_id
 * @return bool|mixed
 * @throws \Error
 */
function wpme_api_update_user($user_from_api, $affilaite_id){
    try {
        $domain = wpme_api_get_base_url();
        $argsAff = wpme_api_get_api_headers_for('wp');
        if ($affiliate = affwp_get_affiliate($affilaite_id)){
            $user = new WP_User($affiliate->user_id);
            // Create User
            // Prep data for aff api
            $name = wpme_parse_name(affwp_get_affiliate_name($affilaite_id));
            $arguments = $name;
            $query = http_build_query($arguments);
            $request = @wp_remote_post(
                $domain . 'wp-json/wp/v2/users/' . $user_from_api->id . '?' . $query,
                array(
                    'headers' => $argsAff['headers'],
                    'method' => 'POST'
                )
            );
            // Bad request
            wpme_check_request(
                $request,
                'WP user updated but Affiliate call failed.',
                3308
            );
            // Ok, we're done
            return $request['response']['code'] === 200 ? json_decode($request['body']) : false;
        } else {
            throw new Exception('No affiliate found when updating mirror image in parent API.', 3305);
        }
    } catch (\Exception $e){
        // Error
        throw new Exception('Error while updating affiliate user remotely, ' . $e->getMessage(), $e->getCode());
    }
}


/**
 * Unique short id
 *
 * @param int $l
 * @return bool|string
 */
function genUuid($l=5){
    return substr(str_shuffle("0123456789abcdefghijklmnopqrstuvwxyz"), 0, $l);
}

/**
 * Spits back false, or ID of user from the remote API
 */
function wpme_api_sync_get_affiliate($checkMail, $domain, $argsAff){
  // payment_email
  // affiliate_id
  $request = @wp_remote_get(
      $domain . 'wp-json/affwp/v1/affiliates?number=9999',
      $argsAff
  );
  // wpme_simple_log('Get aff response ' . var_export($request, true));
  if(is_wp_error($request)){
    return false;
  }
  $body = json_decode($request['body']);
  // Do we have a winner?
  $foundUser = false;
  foreach($body as $affiliateUser){
    // wpme_simple_log('Find ' . $checkMail . ' === ' . $affiliateUser->payment_email ;.' || ' . $affiliateUser->user_email);
    if($affiliateUser->payment_email === $checkMail || $affiliateUser->user_email === $checkMail){
      $foundUser = $affiliateUser;
      break;
    }
  }
  wpme_simple_log('Found user ' . var_export($foundUser, true));
  return $foundUser;
}

/**
 * Update Aff status
 */
function wpme_api_sync_affiliate_status_change($affilaite_id, $new_status = null){
  if(wpme_aff_is_main_domain()){
    return;
  }
  try {
    // Get affilaite ID by email ffrom main site
    // Create if he doesn't exist
    // Update if he does
    // Prep
    $domain = wpme_api_get_base_url();
    $argsAff = wpme_api_get_api_headers_for('aff');
    // Get aff user
    $affiliate = \affwp_get_affiliate($affilaite_id);
    $affiliate_user = new \WP_User((int)$affiliate->user_id);
    $affiliate_email = $affiliate_user->user_email;
    $affiliate_id_remote = wpme_api_sync_get_affiliate($affiliate_email, $domain, $argsAff);
    wpme_simple_log('Got remote user, ' . var_export($affiliate_id_remote, true));
    if(!is_object($affiliate_id_remote)){
      return;
    }
    $arguments = array(
      'status' => $new_status == null ? $affiliate->status : $new_status,
      'rate' => $affiliate->rate,
      'user_id' => $affiliate_id_remote->user_id,
      'rate_type' => $affiliate->rate_type,
      'payment_email' => $affiliate->payment_email,
      'account_email' => $affiliate_user->user_email
    );
    $query = http_build_query($arguments);
    wpme_simple_log('Status to be: ' . $affiliate->status);
    wpme_simple_log('Updating post request to affiliates ' . 'wp-json/affwp/v1/affiliates/' . $affiliate_id_remote->affiliate_id . '?' . $query);
    $request = wpme_patch_to_affilaite_api(
      'affiliates/' . $affiliate_id_remote->affiliate_id . '?' . $query
    );
    wpme_simple_log('Got this back: ' . var_export($request, true));
    wpme_check_request(
        $request,
        'WP Update user status failed.',
        3367
    );
  } catch (\Exception $e){
    // Now what do we do?
  }
}

/**
 * NOTE: This doesn't need wpme_api_create_core_affiliate(...);
 * because it uses /affiliate/ endpoint to create the user through there.
 *
 * @param $affilaite_id
 * @throws \Error
 */
function wpme_api_create_user($affilaite_id){
    if(wpme_aff_is_main_domain()){
        return;
    }
    try {
        $domain = wpme_api_get_base_url();
        $argsAff = wpme_api_get_api_headers_for('aff');
        if ($affiliate = affwp_get_affiliate($affilaite_id)){
            $user = new WP_User($affiliate->user_id);
            $userFromRemote = wpme_api_get_user_by_email($user->user_email);
            if($userFromRemote){
              return;
            }
            // Create User
            $arguments = array(
                'create_user' => true,
                'username' => $user->user_login . genUuid(),
                'rate' => $affiliate->rate,
                'rate_type' => $affiliate->rate_type,
                'payment_email' => $user->user_email,
                'account_email' => $user->user_email,
                'status' => $affiliate->status,
                'notes' => 'Synced from WPME.',
            );
            $query = http_build_query($arguments);
            $request = @wp_remote_post(
                $domain . 'wp-json/affwp/v1/affiliates?' . $query,
                array(
                    'headers' => $argsAff['headers'],
                    'method' => 'POST'
                )
            );
            // Bad request
            wpme_check_request(
                $request,
                'WP user created but Affiliate call failed.',
                3308
            );
            // Ok, we're done
            return $request['response']['code'] === 201 ? json_decode($request['body']) : false;
        } else {
            throw new Exception('No affiliate found when creating mirror image in parent API.', 3305);
        }
    } catch (\Exception $e){
        // Error
        throw new Exception('Error while creating affiliate user remotely, ' . $e->getMessage(), $e->getCode());
    }
}

/**
 * @param $affiliate
 * @param $user_id
 * @return bool|mixed
 * @throws \Exception
 */
function wpme_api_create_core_affiliate($affiliate, $user_id){
    try {
        $domain = wpme_api_get_base_url();
        $argsAff = wpme_api_get_api_headers_for('aff');
        $arguments = array(
            'user_id' => $user_id,
            'rate' => $affiliate->rate,
            'rate_type' => $affiliate->rate_type,
            'payment_email' => $affiliate->payment_email,
            'account_email' => $affiliate->account_email,
            'status' => $affiliate->status,
            'notes' => 'Synced from WPME.',
        );
        $query = http_build_query($arguments);
        $request = @wp_remote_post(
            $domain . 'wp-json/affwp/v1/affiliates?' . $query,
            array(
                'headers' => $argsAff['headers'],
                'method' => 'POST'
            )
        );
        wpme_check_request(
            $request,
            'Error while creating an Affiliate out of Existing WordPress user in Core API.',
            3542
        );
        return $request['response']['code'] === 201 ? json_decode($request['body']) : false;
    } catch (\Exception $e){
        throw new Exception($e->getMessage(), $e->getCode());
    }
}


/**
 * Simple parse name
 */
function wpme_parse_name($fullName = ''){
  $r = array();
  $r['first_name'] = '';
  $r['last_name'] = '';
  // No name
  if(!is_string($fullName)){
    return $r;
  }
  // Easy, just first
  $name_parts = explode(' ', $fullName);
  if(count($name_parts) < 2){
    $r['first_name'] = $name_parts[0];
    return $r;
  }
  // Full name
  $r['first_name'] = $name_parts[0];
  $r['last_name'] = $name_parts[sizeof($name_parts)-1];
  return $r;
}

/**
 * @param $request
 * @param string $msg
 * @param int $code
 * @throws \Error
 */
function wpme_check_request($request, $msg = 'Error while syncing user to the parent API.', $code = 3333){
    if (is_wp_error($request)) {
        throw new Exception($msg . ' ' . $request->get_error_message(), $code);
    }
    if (!is_array($request) || !isset($request['response']['code'])) {
        throw new Exception($msg . ' Invalid HTTP response.', $code);
    }
    $httpCode = (int) $request['response']['code'];
    if ($httpCode >= 500) {
        throw new Exception($msg . ' HTTP ' . $httpCode, $code);
    }
}


/**
 * @param \WP_User $user
 * @param $affilaite_id
 * @throws \Error
 */
function wpme_api_sync_user(\WP_User $user, $affiliate_id = null){
    // wpme_api_get_user_by_email
    // wpme_api_create_user
    $user_email = $user->user_email;
    try {
        // Get user from API
        $user_from_api = wpme_api_get_user_by_email($user_email);
        if($user_from_api === null){
            // User doesn't exist, create him
            wpme_api_create_user($affiliate_id);
        } else {
            // Make him an affilaite if he isn't
            if(!$user_from_api->affiliate_id){
                wpme_api_create_core_affiliate(
                    \affwp_get_affiliate($affiliate_id),
                    $user_from_api->id
                );
            }
            // User exist, update our with username
            wpme_change_existing_username(
                $user->ID,
                $user_from_api->user_name
            );
        }
    } catch(\Exception $e){
        // Error
        throw new Exception('Error while syncing user, ' . $e->getMessage(), $e->getCode());
    }
}

/**
 * This function calls the core APi, checks for user info we need for
 * syncing payout, or referral or something like that, and if user is not there
 * creates him first, then gives the info on what field to use and bam, done.
 *
 * The returned array can be pluged to the request cause it already identifies
 * to whome it belongs.
 *
 * @param $affiliate_id_in_current
 * @return array
 * @throws \Error
 * @throws \Exception
 */
function wpme_api_get_affiliate_detection_from_core($affiliate_id_in_current){
    try {
        $user_id = affwp_get_affiliate_user_id($affiliate_id_in_current);
        $user = new \WP_User($user_id);
        $user_email = $user->user_email;
        // Get user from API
        $user_from_api = wpme_api_get_user_by_email($user_email);
        if($user_from_api === null){
            // Ooops, user is not there, create him
            $user = wpme_api_create_user($affiliate_id_in_current);
            if($user){
                return array('affiliate_id' => $user->affiliate_id);
            }
        } else {
            $affiliate =  \affwp_get_affiliate($affiliate_id_in_current);
            // Make him affiliate (just to be sure)
            if($user_from_api->affiliate_id){
                return array('affiliate_id' => $user_from_api->affiliate_id);
            }
            $user = wpme_api_create_core_affiliate($affiliate, $user_from_api->id);
            return array('affiliate_id' => $user->affiliate_id);
        }
        return array();
    } catch (\Exception $e){
        throw new Exception($e->getMessage(), $e->getCode());
    }
}

/**
 * @return bool
 */
function wpme_api_is_setup(){
    $settings = get_option('WPME_AFFI');
    $check = isset($settings['wpmeAffMainDomain'])
        ? $settings['wpmeAffMainDomain']
        : false;
    if($check === 'off'){
        return
          isset($settings['wpmeAffDomain'])
            &&
          isset($settings['wpmeAffApiPublicKey'])
            &&
          isset($settings['wpmeAffApiToken']);
    }
    return true;
}

/**
 * For Justin
 * https://gist.github.com/Haehnchen/3cb18c40fea2ab883ef1a7a8e25422dc
 * https://gist.github.com/demisang/716250080d77a7f65e66f4e813e5a636
 */

/**
 * Crypto key
 */
define('WPME_CRYPTO', 'df98831f87b994faf07ded1052d9718151519447084');

/**
 * @param $string
 * @param string $crypto
 * @return string
 */
function wpme_encrypt_string($string, $crypto = WPME_CRYPTO){
  return crockford32_encode($string);
}

/**
 * @param $string
 * @param string $crypto
 * @return string
 */
function wpme_decrypt_string($string, $crypto = WPME_CRYPTO){
  return crockford32_decode($string);
}

/**
 * @param $affiliate
 * @return bool
 */
function wpme_affwp_get_affiliate_by_email($affiliate){
    if (!function_exists('affiliate_wp')) {
        return false;
    }
    if ( $user = get_user_by('email', $affiliate ) ) {
        if ( $affiliate = affiliate_wp()->affiliates->get_by( 'user_id', $user->ID ) ) {
            return (int)$affiliate->affiliate_id;
        } else {
            return false;
        }
    }
    return false;
}

/**
 * @param $path
 * @param null $body
 * @param string $error
 * @param int $errorCode
 * @return mixed
 * @throws \Error
 */
function wpme_post_to_affilaite_api(
    $query,
    $body = null,
    $error = '',
    $errorCode = 0
){
    $domain = wpme_api_get_base_url();
    $argsAff = wpme_api_get_api_headers_for('aff');
    $request = @wp_remote_post(
        $domain . 'wp-json/affwp/v1/' . $query,
        array(
            'headers' => $argsAff['headers'],
            'method' => 'POST',
            'body' => $body
        )
    );
    // Bad request
    wpme_check_request(
        $request,
        $error,
        $errorCode
    );
    // Ok, we're done
    return $request;
}

/**
 * @param $query
 * @param null $body
 * @param string $error
 * @param int $errorCode
 * @return mixed
 * @throws \Error
 */
function wpme_patch_to_affilaite_api(
    $query,
    $body = null,
    $error = '',
    $errorCode = 0
){
    $domain = wpme_api_get_base_url();
    $argsAff = wpme_api_get_api_headers_for('aff');
    $request = @wp_remote_request(
        $domain . 'wp-json/affwp/v1/' . $query,
        array(
            'headers' => $argsAff['headers'],
            'method' => 'PATCH',
            'body' => $body
        )
    );
    // Bad request
    wpme_check_request(
        $request,
        $error,
        $errorCode
    );
    // Ok, we're done
    return $request;
}

/**
 * @param $affilaite_id
 */
function wpme_affiliate_created($affilaite_id){
    // Exit early
    // if(wpme_aff_is_main_domain()){
    //     return;
    // }
    // Get user id from affilaite id
    $user_id = affwp_get_affiliate_user_id($affilaite_id);
    if(!is_int($user_id)){
      return;
    }
    // Get user
    $user = new \WP_User($user_id);
    try {
        wpme_api_sync_user($user, $affilaite_id);
    } catch (\Exception $e){
        // Log
    }
    try {
      $affiliate = \affwp_get_affiliate($affilaite_id);
      // Update him in Genoo Cloud
      $api = new GenooWpmeAffilaiteWP();
      $name = wpme_parse_name(affwp_get_affiliate_name($affilaite_id));
      $response = $api->createAffilliate(
          $name['first_name'],
          $name['last_name'],
          $user->user_email,
          $affiliate->status
      );
    } catch (\Exception $e){
        // Log
    }
}

/**
 * @param $user_id
 * @throws \Error
 */
function wpme_affiliate_updated($user_id, $new_status = null){
  // Exit early
  if(wpme_aff_is_main_domain()){
        return;
  }
  // Let's update API in api's
	if(!affwp_is_affiliate($user_id)){
	    return;
  }
  // Update regardless if he's in or not
  $user = new WP_User($user_id);
  $user_email = $user->user_email;
	try {
      // Ok, cool, he's an affialite
      // Does he exist in the other system, if not, don't update right?
      // Get user from API
      $user_from_api = wpme_api_get_user_by_email($user_email);
      $domain = wpme_api_get_base_url();
      $argsAff = wpme_api_get_api_headers_for('aff');
      // Get aff user
      $affiliate_id_remote = wpme_api_sync_get_affiliate($user_email, $domain, $argsAff);
      wpme_simple_log('Get user by ' . var_export($user, true));
      wpme_simple_log('Get user by email ' . var_export($user_email, true));
      wpme_simple_log('Get user ' . var_export($user_from_api, true));
      wpme_simple_log('Get aff ' . var_export($affiliate_id_remote, true));
      // No user in api? 
      if(!$user_from_api){
          // Sync user
          wpme_api_sync_user($user, (int)affwp_get_affiliate_id($user_id));
      } else {
          // He is in the api? Awesome, update him there too
          wpme_api_update_user($user_from_api, (int)affwp_get_affiliate_id($user_id));
          // Update affiliate user
          wpme_api_sync_affiliate_status_change((int)affwp_get_affiliate_id($user_id), $new_status);
      }
      // Cool, he's update here, and sync there
  } catch(\Exception $e){
      //  throw new Exception($e->getMessage(), $e->getCode());
  }
	try {
      $affiliate = \affwp_get_affiliate((int)affwp_get_affiliate_id($user_id));
      // Update him in Genoo Cloud
      $api = new GenooWpmeAffilaiteWP();
      $name = wpme_parse_name(
        affwp_get_affiliate_name((int)affwp_get_affiliate_id($user_id))
      );
      $response = $api->updateAffiliate(
          $name['first_name'],
          $name['last_name'],
          $user_email,
          $affiliate->status
      );
      // Cool, he's update here, and sync there
  } catch(\Exception $e){
        // throw new Exception($e->getMessage(), $e->getCode());
  }    
  // Cool, he is in the api, update the details
}


function getAffiliateWpLeadType(){
    $option = get_option('genooLeads');
    if(isset($option['genooLeadUserAffiliate'])){
        return (int)$option['genooLeadUserAffiliate'];
    }
    return false;
}
