<?php
/*
    Plugin Name: Affiliate-wp - WPMktgEngine Extension
    Description: Genoo, LLC
    Author:  Genoo, LLC
    Author URI: http://www.genoo.com/
    Author Email: info@genoo.com
    Version: 1.4.0
    License: GPLv2
*/
/*
    Copyright 2015  WPMKTENGINE, LLC  (web : http://www.genoo.com/)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

// Constants
define('LEAD_COOKIE', '_gtld');
define('LEAD_META_ID',  'c00affiliateid');
define('LEAD_META_REF', 'c00referred_by_affiliate_id'); // HERE
define('LEAD_META_REF_DATE', 'c00referred_by_affiliate_id_date'); // HERE
define('LEAD_META_REF_USER', 'c00referred_by_affiliate_user_id');
define('LEAD_META_REF_LEAD', 'c00referred_by_affiliate_lead_id');
define('LEAD_META_REF_REAL_ID', 'LEAD_META_REF_REAL_ID');
define('LEAD_META_SOLD','c00sold_by_affiliate_id'); // HERE
define('LEAD_META_SOLD_USER','c00sold_by_affiliate_user_id');
define('LEAD_META_SOLD_LEAD','c00sold_by_affiliate_lead_id');
$LEAD_TYPES_ARRAY = array(
    LEAD_META_REF,
    LEAD_META_REF_LEAD,
    LEAD_META_REF_USER,
    LEAD_META_SOLD,
    LEAD_META_SOLD_LEAD,
    LEAD_META_SOLD_USER,
    LEAD_META_REF_DATE
);

/**
 * On activation
 */

register_activation_hook(__FILE__, function(){
    // Basic extension data
    $fileFolder = basename(dirname(__FILE__));
    $file = basename(__FILE__);
    $filePlugin = $fileFolder . DIRECTORY_SEPARATOR . $file;
    // Activate?
    $activate = FALSE;
    $isGenoo = FALSE;
    // Get api / repo
    if(class_exists('\WPME\ApiFactory') && class_exists('\WPME\RepositorySettingsFactory')){
        $activate = TRUE;
        $repo = new \WPME\RepositorySettingsFactory();
        $api = new \WPME\ApiFactory($repo);
        if(class_exists('\Genoo\Api')){
            $isGenoo = TRUE;
        }
    } elseif(class_exists('\Genoo\Api') && class_exists('\Genoo\RepositorySettings')){
        $activate = TRUE;
        $repo = new \Genoo\RepositorySettings();
        $api = new \Genoo\Api($repo);
        $isGenoo = TRUE;
    } elseif(class_exists('\WPMKTENGINE\Api') && class_exists('\WPMKTENGINE\RepositorySettings')){
        $activate = TRUE;
        $repo = new \WPMKTENGINE\RepositorySettings();
        $api = new \WPMKTENGINE\Api($repo);
    }
    // 1. First protectoin, no WPME or Genoo plugin
    if($activate == FALSE){
        genoo_wpme_deactivate_plugin(
            $filePlugin,
            'This extension requires WPMktgEngine or Genoo plugin to work with.'
        );
    } else {
        // Right on, let's run the tests etc.
        // 2. Second test, can we activate this extension?
        // Active
        $active = TRUE;
        // 3. Check if we can activate the plugin after all
        if($active === FALSE){
            genoo_wpme_deactivate_plugin(
                $filePlugin,
                'This extension is not allowed as part of your package.'
            );
        } else {
            // 4. After all we can activate, that's great, lets add those calls
            // Might be older package
            $ch = curl_init();
            if(defined('GENOO_DOMAIN')){
                curl_setopt($ch, CURLOPT_URL, 'https:' . GENOO_DOMAIN . '/api/rest/affiliateenable/true');
            } else {
                curl_setopt($ch, CURLOPT_URL, 'https:' . WPMKTENGINE_DOMAIN . '/api/rest/affiliateenable/true');
            }
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("X-API-KEY: " . $api->key));
            $resp = curl_exec($ch);
            if(!$resp){
                $active = FALSE;
                $error = curl_error($ch);
                $errorCode = curl_errno($ch);
            } else {
                if(curl_getinfo($ch, CURLINFO_HTTP_CODE) == 202){
                    // Active whowa whoooaa
                    $active = TRUE;
                    // now, get the lead_type_id
                    $json = json_decode($resp);
                }
            }
            curl_close($ch);
        }
        if($active == FALSE){
            genoo_wpme_deactivate_plugin(
                $filePlugin,
                'Activation failed: ' . $error . ' : ' . $errorCode
            );
        }
    }
});

/**
 * Plugin Updates
 */

include_once( plugin_dir_path( __FILE__ ) . 'deploy/updater.php' );
wpme_updater_init(__FILE__);

/**
 * Add basic auth
 */

include_once 'lib/api.php';
include_once 'lib/api-sync.php';
include_once 'lib/basic-auth.php';
include_once 'lib/basic-auth-fix.php';

/**
 * ========================================================================================
 * Basic
 * ========================================================================================
 */

/**
 * Don't forget cookie on login
 */
add_action('wp_login', function($user_login, $user){
    $lead_id = \get_user_meta($user->ID, LEAD_COOKIE, TRUE);
    if(is_numeric($lead_id)){
        setcookie(LEAD_COOKIE, $lead_id, time() + 31556926, COOKIEPATH, COOKIE_DOMAIN);
    }
}, 1, 2);

/**
 * Start session
 */

add_action('init', function(){
    if(!is_admin()){
        if (version_compare(phpversion(), '5.4.0', '<')) {
            if(session_id() == '') {
                session_start();
            }
        } else {
            if (session_status() == PHP_SESSION_NONE) {
                session_start();
            }
        }
    }
}, 10, 1);


/**
 * ========================================================================================
 * Affiliates + status
 * ========================================================================================
 */

function WPMEgetApiv2(){
    global $WPME_API;
    return $WPME_API;
}

/**
 * Change status
 * TOOD: add affiliate lead type
 */
add_action('affwp_set_affiliate_status', function($affiliate_id, $status, $old_status){
  wpme_simple_log('Status change ' . $status . ' ' . $affiliate_id);
  // Get affiiate
  $affiliate = \affwp_get_affiliate($affiliate_id);
  $affiliate_user = new \WP_User((int)$affiliate->user_id);
  $affiliate_email = $affiliate_user->user_email;
  // Prep data
  $user_username = $affiliate_user->user_login;
  $user_email = $affiliate_user->user_email;
  $user_email_payement = $affiliate->payment_email;
  $user_url = $affiliate_user->user_url;
  // $affiliate_user->add_role(LEAD_ROLE);
  // Get API
  $api = WPMEgetApiv2();
  wpme_simple_log('Status should be updated to: ' . $status . ' from: ' . $old_status);
  wpme_affiliate_updated((int)$affiliate->user_id, $status);
  // Get lead id
  if($api !== FALSE){
      try {
        $name = wpme_parse_name(affwp_get_affiliate_name($affiliate_id));
        // $leadType, $email, $first_name = '', $last_name = '', $web_site_url = '', $update = false, $additional 
        $lead_id_api = $api->setLead(
            getAffiliateWpLeadType(),
            $user_email,
            $name['first_name'],
            $name['last_name'],
            $user_url,
            true,
            array(
                'c00affiliateid' => $affiliate_id,
                'c00affiliate_hash' => wpme_encrypt_string($user_email),
            )
        );
        \update_user_meta($affiliate_user->ID, LEAD_COOKIE, $lead_id_api);
      } catch (\Exception $e){
            // Error here
            $lead_id_api = NULL;
      }
  }
}, 90, 3);

/**
 * ========================================================================================
 * Plugin + Helpers
 * ========================================================================================
 */

/**
 * Affilaite WP in menu
 */
add_filter('wpmktengine_tools_extensions_widget', function($array){
    $array['Affiliate-WP'] = '<span style="color:green">Active</span>';
    return $array;
}, 10, 1);

/**
 * @return int|mixed
 */
function getAffiliateId(){
    $affiliate_id = null;
    $back_aff = null;
    // Check if it's in cookies first
    if(isset($_COOKIE['affwp_ref'])){
        $back_aff = (int)$_COOKIE['affwp_ref'];
    }
    // Var
    add_filter('affwp_use_fallback_tracking_method', '__return_true');
    $aff = new Affiliate_WP_Tracking();
    $affiliate_id = $aff->referral;
    if(empty($affiliate_id) && method_exists($aff, 'get_fallback_affiliate_id')){
        $affiliate_id = $aff->get_fallback_affiliate_id();
    }
    if(empty($affiliate_id)){
        return $back_aff;
    }
    if(!is_numeric($affiliate_id)){
        $affiliate_id = $aff->get_affiliate_id_from_login($affiliate_id);
    }
    $affiliate_id = absint( $affiliate_id );
    add_filter('affwp_use_fallback_tracking_method', '__return_false');
    // Give back!
    return is_numeric($affiliate_id) ? $affiliate_id : $back_aff;
}

/**
 * @param $userId
 * @return mixed
 */
function getLidFromAffiliateId($userId){
    $data = get_user_meta($userId, LEAD_COOKIE, true);
    return $data === '' ? null : $data;
}

/**
 * On load
 */
add_action('after_setup_theme', function(){
    // Exit early
    if(defined( 'DOING_AJAX' ) && DOING_AJAX){
        return;
    }
    if(is_admin()){
        return;
    }
    $affiliate_id = getAffiliateId();
    $affiliate_id = (int)$affiliate_id;
    if(!is_int($affiliate_id) || $affiliate_id == null || $affiliate_id === 0){
        wpme_might_have_affiliate();
        return;
    }
    if(wpme_aff_is_main_domain()){
        // Ok, we are on the main domain and we have this
        $cookieSetMain = isset($_COOKIE['affwp_ref']) ? $_COOKIE['affwp_ref'] : null;
        $cookieSet = isset($_COOKIE[LEAD_META_SOLD]) ? $_COOKIE[LEAD_META_SOLD] : null;
        // Convert has into email and find user ID
        $cookieSet = wpme_get_affiliate_by_hash($cookieSet);
        // These two cookies exist, but they don't match
        if(($cookieSet !== null) && ($cookieSetMain !== null) && ($cookieSet !== $cookieSetMain)){
          // We have been transferred by the id is different
          $refName = affiliate_wp()->settings->get('referral_var', 'ref');
          // Set new value
          $_GET[$refName] = $cookieSet;
          $_GLOBALS[$refName] = $cookieSet;
          $_COOKIE[$refName] = $cookieSet;
          // Set real cookie
          affiliate_wp()->tracking->set_affiliate_id($cookieSet);
          affiliate_wp()->tracking->fallback_track_visit();
          $affiliate_id = $cookieSet;
        }
    }
    // Get user id from affilaite id
    $user_id = affwp_get_affiliate_user_id($affiliate_id);
    // Reffered by LID
    $lead_id = getLidFromAffiliateId($user_id);
    // Save to session hey
    saveRefferedByToSession($affiliate_id,  $user_id, $lead_id);
    saveSoldByToSession($affiliate_id,  $user_id, $lead_id);
}, 1, 1);

/**
 * Save affiliate ref in this system/
 */
function wpme_might_have_affiliate(){
  // Exit early
  if(!function_exists('affiliate_wp')){
    return;
  }
  $first = isset($_COOKIE[LEAD_META_SOLD]) ? $_COOKIE[LEAD_META_SOLD] : null;
  $second = isset($_COOKIE[LEAD_META_REF]) ? $_COOKIE[LEAD_META_REF] : null;
  $hash = false; 
  // Check
  if(is_string($first)){
    $hash = $first;
  } elseif(is_string($second)){
    $hash = $second;
  }
  // Hash
  if(!is_string($hash)){
    return;
  }
  // Convert has into email and find user ID
  $affiliate_id_from_hash = wpme_get_affiliate_by_hash($hash);
  // If found, set as a cookie
  if(is_int($affiliate_id_from_hash) && $affiliate_id_from_hash > 0){
    // Set cookie
    affiliate_wp()->tracking->set_affiliate_id($affiliate_id_from_hash);
  }
}


/**
 * Save where needed
 *
 * @param null $affiliate_id
 * @param null $user_id
 * @param null $lead_id
 */
function saveRefferedByToSession($affiliate_id = null, $user_id = null, $lead_id = null){
    if(!function_exists('affwp_is_active_affiliate')){
      return;
    }
    if(!affwp_is_active_affiliate($affiliate_id)){
      return;
    }
    if($affiliate_id !== null){
        $affiliate_id_original = $affiliate_id;
        $affiliate_id = wpme_always_affiliate_hash($affiliate_id);
        if(isset($_SESSION[LEAD_META_REF])){
            $_SESSION[LEAD_META_SOLD] = $affiliate_id;
        } else {
            $_SESSION[LEAD_META_REF] = $affiliate_id;
            $_SESSION[LEAD_META_REF_DATE] = date("c");
        }
        // Set real ID
        setcookie(
          LEAD_META_REF_REAL_ID,
          $affiliate_id_original,
          wpme_get_cookie_aff_time(),
          COOKIEPATH,
          COOKIE_DOMAIN
        );
        if(isset($_COOKIE[LEAD_META_REF])){
            setcookie(
                LEAD_META_SOLD,
                $affiliate_id,
                wpme_get_cookie_aff_time(),
                COOKIEPATH,
                COOKIE_DOMAIN
            );
        } else {
            setcookie(
                LEAD_META_REF,
                $affiliate_id,
                wpme_get_cookie_aff_time(),
                COOKIEPATH,
                COOKIE_DOMAIN
            );
            setcookie(
                LEAD_META_REF_DATE,
                date("c"),
                wpme_get_cookie_aff_time(),
                COOKIEPATH,
                COOKIE_DOMAIN
            );
        }
    }
}

/**
 * @param null $affiliate_id
 * @param null $user_id
 * @param null $lead_id
 */
function saveSoldByToSession($affiliate_id = null, $user_id = null, $lead_id = null){
    if(!function_exists('affwp_is_active_affiliate')){
      return;
    }
    if(!affwp_is_active_affiliate($affiliate_id)){
      return;
    }
    if($affiliate_id !== null){
        $affiliate_id = wpme_always_affiliate_hash($affiliate_id);
        $_SESSION[LEAD_META_SOLD] = $affiliate_id;
        setcookie(
            LEAD_META_SOLD,
            $affiliate_id,
            wpme_get_cookie_aff_time(),
            COOKIEPATH,
            COOKIE_DOMAIN);
    }
}

/**
 * Clear All REferal from session (after lead is created)
 */
function clearRefferalFromSession(){
    $array = array(
        LEAD_META_REF,
        LEAD_META_REF_LEAD,
        LEAD_META_REF_USER,
        LEAD_META_SOLD,
        LEAD_META_SOLD_LEAD,
        LEAD_META_SOLD_USER,
        LEAD_META_REF_DATE,
        LEAD_META_REF_REAL_ID
    );
    foreach($array as $key => $itemToUnset){
        if(isset($_SESSION[$itemToUnset])){
            unset($_SESSION[$itemToUnset]);
            wpmeRemoveCookie($itemToUnset);
        }
    }
}

/**
 * Remove Coookie
 */
function wpmeRemoveCookie($name){
    @setcookie($name, NULL, time()-3600, COOKIEPATH, COOKIE_DOMAIN);
    unset($_COOKIE[$name]);
}

/**
 * Cookie time depending on AFF settings
 */
function wpme_get_cookie_aff_time(){
  return strtotime('+' . affiliate_wp()->tracking->get_expiration_time() . ' days');
}

/**
 * Add aff hash to form
 */
add_filter('genoo_wpme_form_reducer', function($data, $key){
  if(defined( 'DOING_AJAX' ) && DOING_AJAX){
    return $data;
  }
  if(!function_exists('affwp_get_affiliate_email')){
    return $data;
  }
  $affId = getAffiliateId();
  if(!$affId){
    return $data;
  }
  $hash = wpme_encrypt_string(affwp_get_affiliate_email($affId));
  if(!is_string($hash)){
    return $data;
  }
  // String to append
  $append = '<input type="hidden" name="refaffid" value="'. $hash .'" />';
  return str_replace(
    '<input type="hidden" name="form_key"',
    $append . '<input type="hidden" name="form_key"',
    $data
  );
}, 100, 2);


/**
 * Lead meta aff
 */
add_action('shutdown', function(){
  return;
  if(defined( 'DOING_AJAX' ) && DOING_AJAX){
    return;
  }
  if(!function_exists('affwp_get_affiliate_email')){
    return;
  }
  if(!isset($_COOKIE[LEAD_META_REF])){
    return;
  }
  if(!isset($_COOKIE[LEAD_META_REF_DATE])){
    return;
  }
  if(!isset($_COOKIE[LEAD_META_SOLD])){
    return;
  }
  $leadId = isset($_GET['upid']) ? (int)$_GET['upid'] : false;
  $leadFormResult = isset($_GET['formResult']) ? ($_GET['formResult'] === 'true' ? true : false) : false;
  if(!is_int($leadId)){
    return;
  }
  if(!$leadFormResult){
    return;
  }
  // Ok, we are here and we have all we needs
  $api = WPMEgetApiv2();
  // Get lead by id
  try {
    $lead = $api->getLead($leadId);
  } catch (\Exception $e){
    $lead = false;
  }
  if($lead === false || $lead === '' || !is_object($lead)){
    return;
  }
  // Cool lets update the guy, gather these
  $params = array();
  $arrayToCheck = [LEAD_META_REF, LEAD_META_REF_DATE, LEAD_META_SOLD];
  foreach($arrayToCheck as $cookieToCheck){
    if(isset($_COOKIE[$cookieToCheck])){
      // Add value to first lead in array
      $params[$cookieToCheck] = $_COOKIE[$cookieToCheck];
    }
  }
  // We have a lead, update him
  try {
    $result = $api->callCustom(
      $api::POST_LEADS,
      'POST',
      array(
        'updateadd' => true,
        'leads' => array(
          array_merge(
            array(
              'email' => $lead->lead->email,
            ),
            $params
          )
        )
      )
    );
  } catch (\Exception $e){
  }
}, 15, 1);

/**
 * Use Cookie Domain
 */
// add_filter('affwp_tracking_cookie_domain', function($domain){
//   if(defined('COOKIE_DOMAIN')){
//     return COOKIE_DOMAIN;
//   }
//   return $domain;
// }, 999, 1);

/**
 * Ecommerce new order
 */
add_filter('genoo_wpme_lead_creation_attributes', function($atts, $type){
    $array = array(
        LEAD_META_REF,
        LEAD_META_REF_LEAD,
        LEAD_META_REF_USER,
        LEAD_META_SOLD,
        LEAD_META_SOLD_LEAD,
        LEAD_META_SOLD_USER,
        LEAD_META_REF_DATE
    );
    $added = false;
    switch($type){
        case 'ecommerce-new-order-lead':
        case 'ecommerce-new-order-lead-update':
        case 'ecommerce-register-new-customer-lead':
            foreach($array as $key => $itemToUnset){
                if(isset($_COOKIE[$itemToUnset])){
                    $val = $_COOKIE[$itemToUnset];
                    $atts[$itemToUnset] = $val;
                    $added = true;
                }
            }
            if($_COOKIE[LEAD_META_SOLD]){
              $atts['c00referred_by_affiliate_id_date'] = \WPME\Ecommerce\Utils::getDateTime();
              $atts['c00sold_by_affiliate_id'] = $_COOKIE[LEAD_META_SOLD];
              $atts['c00referred_by_affiliate_id'] = $_COOKIE[LEAD_META_REF];
            } else {
              $atts = wpme_proceed_try_aff($atts, $type);
            }
            return $atts;
    }
    return $atts;
}, 10, 2);

function wpme_proceed_try_aff($atts, $type = ''){
  // Do magic
  $recordAff = false;
  // Lead user email before creating it
  $email = $atts['email'];
  if(!$email){
    return $atts;
  }
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)){
    return $atts;
  }
  // Cool valid email
  $api = new GenooWpmeAffilaiteWP();
  $refferedLead = $api->getLeadRef($email);
  // Can we continue now?
  if(!is_array($refferedLead)){
    return $atts;
  }
  // Record it
  $recordAff = true;
  
  // Ok ... let's the do the magic here
  // 1. Get by email, hash value /leadreferredbyemail/{email}
  // 2. Current date - referred_by_affiliate_id_date (cookie expiratoin days)
  // 3. Find affiliate locally and credit
  // 4. Send to main site
  return $atts + $refferedLead;
}

/**
 * Genoo / WPME deactivation function
 */
if(!function_exists('genoo_wpme_deactivate_plugin')){

    /**
     * @param $file
     * @param $message
     * @param string $recover
     */

    function genoo_wpme_deactivate_plugin($file, $message, $recover = '')
    {
        // Require files
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        // Deactivate plugin
        deactivate_plugins($file);
        unset($_GET['activate']);
        // Recover link
        if(empty($recover)){
            $recover = '</p><p><a href="'. admin_url('plugins.php') .'">&laquo; ' . __('Back to plugins.', 'wpmktengine') . '</a>';
        }
        // Die with a message
        wp_die($message . $recover);
        exit();
    }
}

/**
 * Genoo / WPME json return data
 */
if(!function_exists('genoo_wpme_on_return')){

    /**
     * @param $data
     */

    function genoo_wpme_on_return($data)
    {
        @error_reporting(0); // don't break json
        header('Content-type: application/json');
        die(json_encode($data));
    }
}



if(!function_exists('wpme_simple_log')){

    /**
     * @param        $msg
     * @param string $filename
     * @param bool   $dir
     */
    function wpme_simple_log($msg, $filename = 'log.log', $dir = FALSE)
    {
      if(class_exists('\Tracy\Debugger')){
        // \Tracy\Debugger::barDump($msg);
      }
        if(1 === 2){
            @date_default_timezone_set('UTC');
            @$time = date("F j, Y, g:i a e O");
            @$time = '[' . $time . '] ';
            @$saveDir =  __DIR__;
            if(is_array($msg) || is_object($msg)){
                $msg = print_r($msg, true);
            }
            @error_log($time . $msg . "\n", 3, $saveDir . DIRECTORY_SEPARATOR  .$filename);
        }
    }
}

// Add settings
// Add Details to WPME order obejct
add_filter('genoo_wpme_api_params', function($params, $action){
  wpme_simple_log('I am called with params ' . var_export($params, true));
  wpme_simple_log('and action ' . var_export($action, true));
    switch($action){
        case '/wpmeorders':
        case '/wpmeorders[S]':
            if(!is_array($_COOKIE)){
              return $params;
            }
            if(
                array_key_exists(LEAD_META_REF, $_COOKIE)
                && isset($params) && is_array($params['params'])
                && !array_key_exists('ReferredByAffiliateID', $params['params'])
            ){
                $params['params']['ReferredByAffiliateID'] = $_COOKIE[LEAD_META_REF];
                $params['params']['affiliate_hash'] = $_COOKIE[LEAD_META_REF];
                $params['params']['affiliate_id'] = $_COOKIE[LEAD_META_REF];
            }
            if(
                array_key_exists(LEAD_META_SOLD, $_COOKIE)
                && isset($params) && is_array($params['params'])
                && !array_key_exists('SoldByAffiliateID', $params['params'])
            ){
                $params['params']['SoldByAffiliateID'] = $_COOKIE[LEAD_META_SOLD];
            }
            return $params;
          case '/leads':
            // If we're creating leads, and it's just one, and aff is enabled
            if(is_array($params['leads']) && count($params['leads']) === 1 && function_exists('affwp_get_affiliate_email')){
              // We are creating a lead
              $arrayToCheck = [LEAD_META_REF, LEAD_META_REF_DATE, LEAD_META_SOLD];
              foreach($arrayToCheck as $cookieToCheck){
                if(isset($_COOKIE[$cookieToCheck])){
                  // Add value to first lead in array
                  $params['leads'][0][$cookieToCheck] = $_COOKIE[$cookieToCheck];
                }
              }
            }
            wpme_simple_log('Create lead ' . var_export($params, true));
            return $params;
          break;
          default:
            return $params;
    }
    return $params;
}, 10, 2);

// Cach lead types

/**
 * Add settings page
 *  - if not already in
 */
add_filter('wpmktengine_settings_sections', function($sections){
    $sections[] = array(
        'id' => 'WPME_AFFI',
        'title' => __('Affiliate WP', 'wpmktengine')
    );
    return $sections;
}, 10, 1);


function sample_admin_notice__success() {
    ?>
    <div class="notice notice-success is-dismissible">
        <p><?php _e( 'Done!', 'sample-text-domain' ); ?></p>
    </div>
    <?php
}
add_action( 'admin_notices', function(){
    if(isset($_GET['wpme-flush-rules'])){
        flush_rewrite_rules();
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Rewrite rules have been flushed and custom HTTP condtions applied.</strong></p>
        </div>
        <?php
    }
});

/**
 * Add fields to settings page
 */
add_filter('wpmktengine_settings_fields', function($fields){
    $noteText = '<strong style="color:red">NOTE:</strong> Make sure all endpoints for <br />
                POST, PUT, PATCH, DELETE are enabled in Affiliate WP -> Settings -> REST API';
    $fields['WPME_AFFI'] = array(
        array(
            'label' => 'Is this website the main sync domain?',
            'name' => 'wpmeAffMainDomain',
            'type' => 'checkbox',
            'desc' => '<br /><br /><strong style="color:red">NOTE:</strong><br />
             php-cgi under Apache does not pass HTTP Basic user/pass to PHP by default<br />
             For this workaround to work, add these lines to your .htaccess file:<br />
             <code>RewriteCond %{HTTP:Authorization} ^(.+)$<br />
             RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]<br />
             </code><br />
             We can do this for you, just <a class="button-primary" href="'. admin_url('admin.php?page=WPMKTENGINE&wpme-flush-rules=1') .'">click here.</a>'
        ),
        array(
            'label' => 'Affiliate WP: Main domain',
            'name' => 'wpmeAffDomain',
            'type' => 'text',
            'desc' => $noteText,
            'attr' => array(
                'placeholder' => 'http://www.example.com/',
                'pattern' => 'https?://.+'
            ),
        ),
        array(
            'label' => 'Affiliate WP: Public Key',
            'name' => 'wpmeAffApiPublicKey',
            'type' => 'text',
            'desc' => '<code>Affiliates -> Tools -> Api Keys</code>'
        ),
        array(
            'label' => 'Affiliate WP: Token',
            'name' => 'wpmeAffApiToken',
            'type' => 'text',
            'desc' => '<code>Affiliates -> Tools -> Api Keys</code>',
        ),
    );
    // User lead roles
    $options = $fields['genooLeads'][1]['options'];
    $fields['genooLeads'][] = array(
        'name' => "genooLeadUserAffiliate",
        'label' => "Affiliate (AffiliateWp)",
        'type' => "select",
        'options' => $options
    );
    return $fields;
}, 909, 1);

/**
 * On saving of api settings
 */
add_action('update_option_affwp_settings', function($oldvalue, $_newvalue){
    // Details
    $refValueOld = $oldvalue['referral_var'];
    $refValueNew = $_newvalue['referral_var'];
    if($refValueNew !== $refValueOld){
      $api = new GenooWpmeAffilaiteWP();
      $response = $api->updateRefVariable($refValueNew);
    }
}, 10, 2);

/**
 * On saving of api settings
 */
add_action('update_option_WPME_AFFI', function($oldvalue, $_newvalue){
    // Details
    $check = $_newvalue['wpmeAffMainDomain'];
    $domain = @$_newvalue['wpmeAffDomain'];
    $public = @$_newvalue['wpmeAffApiPublicKey'];
    $token = @$_newvalue['wpmeAffApiToken'];
    if($check === 'off'){
        $args = array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode($public . ':' . $token),
            )
        );
        $result = wp_remote_get(rtrim($domain, '/') . '/wp-json/affwp/v1/affiliates', $args);
        if($result instanceof \WP_Error || ($result['response']['code'] !== 200 && $result['response']['code'] !== 404)){
            // Oh oh, wrong domain, or details
            if(is_array($result) && $result['response']['code'] === 403){
                add_settings_error(
                    'wpmeAffApiPublicKey',
                    'genooId' . 'wpmeAffApiPublicKey',
                    'Given API Public Key is not valid.',
                    'error'
                );
                add_settings_error(
                    'wpmeAffApiToken',
                    'genooId' . 'wpmeAffApiToken',
                    'Given API Token is not valid.',
                    'error'
                );
            } else {
                add_settings_error(
                    'wpmeAffDomain',
                    'genooId' . 'wpmeAffDomain',
                    'It looks like the domain for your API sync is not valid.',
                    'error'
                );
            }
        } else {
            // All good, even 404 means no affiliates found
        }
    } else {
        $_newvalue['wpmeAffDomain'] = '';
        $_newvalue['wpmeAffApiPublicKey'] = '';
        $_newvalue['wpmeAffApiToken'] = '';
        $updated = update_option('WPME_AFFI', $_newvalue);
    }
}, 10, 2);


/**
 * ========================================================================================
 * Users
 * ========================================================================================
 */
/**
 * Fires immediately after registering a user.
 *
 * @param int    $affiliate_id Affiliate ID.
 * @param string $status       Affiliate status.
 * @param array  $args         Data arguments used when registering the user.
 */
add_action( 'affwp_register_user', 'wpme_affiliate_created', 10, 1 );

/**
 * Fires immediately after a new user has been auto-registered as an affiliate
 *
 * @since  1.7
 *
 * @param int    $affiliate_id Affiliate ID.
 * @param string $status       The affiliate status.
 * @param array  $args         Affiliate data.
 */
add_action( 'affwp_auto_register_user', 'wpme_affiliate_created', 10, 1 );

/**
 * Fires immediately after an affiliate has been added to the database.
 *
 * @param int   $add  The new affiliate ID.
 * @param array $args The arguments passed to the insert method.
 */
add_action( 'affwp_insert_affiliate', 'wpme_affiliate_created', 10, 1 );


/**
 * Fires immediately after an affiliate has been updated.
 *
 * @since 1.8
 *
 * @param stdClass $affiliate Updated affiliate object.
 * @param bool     $updated   Whether the update was successful.
 */
add_action( 'profile_update', 'wpme_affiliate_updated', 1, 2);


/**
 * Convert hashed email address - if it is one
 */
add_filter('affwp_tracking_get_affiliate_id', function ($id, $login = null){
    if($login){
        return wpme_get_affiliate_by_hash($login);
    }
    return $id;
}, 1, 2);

add_filter( 'rest_user_query', 'prefix_remove_has_published_posts_from_wp_api_user_query', 10, 2 );
/**
 * Removes `has_published_posts` from the query args so even users who have not
 * published content are returned by the request.
 *
 * @see https://developer.wordpress.org/reference/classes/wp_user_query/
 *
 * @param array           $prepared_args Array of arguments for WP_User_Query.
 * @param WP_REST_Request $request       The current request.
 *
 * @return array
 */
function prefix_remove_has_published_posts_from_wp_api_user_query( $prepared_args, $request ) {
    unset( $prepared_args['has_published_posts'] );
    return $prepared_args;
}


/**
 * Get by hash
 */
function wpme_get_affiliate_by_hash($login){
  $decrypted = wpme_decrypt_string($login);
  if (filter_var($decrypted, FILTER_VALIDATE_EMAIL)){
    $affiliate = wpme_affwp_get_affiliate_by_email($decrypted);
    if(is_int($affiliate)){
      return $affiliate;
    }
  }
  return false;
}


/**
 * This always ansures AFF ID is hash_id
 */
function wpme_always_affiliate_hash($hashId){
  if(is_int($hashId)){
    // affwp_get_affiliate_email
    // affwp_get_affiliate_payment_email
    return wpme_encrypt_string(
      affwp_get_affiliate_email($hashId)
    );
  }
  return $hashId;
}

/**
 * Show hashed option as only option
 */
add_filter('affwp_settings_referral_format', function ($arr){
    return array(
        'hash' => 'Hashed email address',
    );
}, 999, 1);

/**
 * Generate ref links
 */
add_filter('affwp_get_referral_format_value', function($value, $format, $affiliate_id){
    if ($affiliate = affwp_get_affiliate($affiliate_id)){
		$user = new WP_User($affiliate->user_id);
		return wpme_encrypt_string($user->user_email);
	}
	return $value;
}, 999, 3);

/**
 * Sync things back to main API
 */

// Acccepted
add_action('affwp_set_referral_status', function($referral_id, $new_status, $old_status){

}, 99, 3);

/**
 * ========================================================================================
 * Payouts
 * ========================================================================================
 */

/**
 * Insert payout
 */
add_action('affwp_insert_payout', function($payout_id){
    // Exit early
    if(wpme_aff_is_main_domain()){
        return;
    }
    try {
        // Payout object
        $payout = affwp_get_payout($payout_id);
        // Parse ->referrals field
        // iterate through and get real referral id's in the future system
        // sent
        $referrals = explode(',', $payout->referrals);
        $referralsRemote = array();
        // NOTE: if the id is not there, in the core
        // it won't get updated as a payout.
        foreach($referrals as $referralId){
            $referralObject = affwp_get_referral(intval($referralId));
            $referralsRemote[] = intval($referralObject->custom);
        }
        // Implode into one string
        $referralsRemoteString = implode(',', $referralsRemote);
        // Affiliate info
        $affiliate_array_target = wpme_api_get_affiliate_detection_from_core($payout->affiliate_id);
        $arguments = array(
            'referrals' => $referralsRemoteString,
            'amount' => $payout->amount,
            'payout_method' => $payout->payout_method,
            'status' => $payout->status,
        );
        if(is_array($affiliate_array_target)){
          $arguments = array_merge($arguments, $affiliate_array_target);
        }
        $query = http_build_query($arguments);
        $request = wpme_post_to_affilaite_api(
            'payouts?' . $query,
            null,
            'Error creating a referral.',
            3356
        );
        // Hopefully all good here?
    } catch (\Exception $e){
        // TODO: log
    }
}, 10, 1);

/**
 * ========================================================================================
 * Referrals
 * ========================================================================================
 */

/**
 * Insert referrals
 */
add_action('affwp_insert_referral', function($referral_id){
    // Exit early
    if(wpme_aff_is_main_domain()){
        return;
    }
    try {
        $referral = affwp_get_referral($referral_id);
        // Get affiliate ID from the system above
        $affiliate_id = $referral->affiliate_id;
        $affiliate_array_target = wpme_api_get_affiliate_detection_from_core($affiliate_id);
        // Get affiliate from other system
        // send
        $arguments = array(
            'amount' => $referral->amount,
            'currency' => $referral->currency,
            'description' => $referral->description,
            'reference' => $referral->reference,
            'context' => $referral->context,
            'status' => $referral->status,
        );
        if(is_array($affiliate_array_target)){
          $arguments = array_merge($arguments, $affiliate_array_target);
        }
        $query = http_build_query($arguments);
        $request = wpme_post_to_affilaite_api(
            'referrals?' . $query,
            null,
            'Error creating a referral.',
            3356
        );
        // Get created referal
        $created_referral = json_decode($request['body']);
        // Created referral, now we can update ours with the "ID" from the core one ...
        affiliate_wp()->referrals->update(
            $referral_id,
            array(
                'custom' => $created_referral->referral_id
            ),
            '',
            'referral'
        );
        // I think we're done here yay
    } catch (\Exception $e){
        // TODO: Log
    }
}, 10, 1);

/**
 * Update referral
 */

/**
 * Fires immediately after a referral update has been attempted.
 *
 * @since 2.1.9
 *
 * @param \AffWP\Referral $updated_referral Updated referral object.
 * @param \AffWP\Referral $referral         Original referral object.
 * @param bool            $updated          Whether the referral was successfully updated.
 */
add_action( 'affwp_updated_referral', 'wpme_affiliate_referral_update', 10, 3);

/**
 * Fires immediately after a referral's status has been successfully updated.
 *
 * Will not fire if the new status matches the old one, or if `$new_status` is empty.
 *
 * @since
 *
 * @param int    $referral_id Referral ID.
 * @param string $new_status  New referral status.
 * @param string $old_status  Old referral status.
 */
add_action( 'affwp_set_referral_status', 'wpme_affiliate_referral_update', 10, 3);

/**
 * Referral update
 */
function wpme_affiliate_referral_update(
    $referral_or_id,
    $original_referrral_or_new_status,
    $updated_or_old_status
){
    // Exit early
    if(wpme_aff_is_main_domain()){
        return;
    }
    // First things first
    $isUpdatedReferral = true;
    $isUpdatedStatus = false;
    if(is_numeric($referral_or_id)){
        $isUpdateReferral = false;
        $isUpdatedStatus = true;
    }
    $arguments = array();
    if($isUpdatedStatus){
        $arguments['status'] = $original_referrral_or_new_status;
        $referral = affwp_get_referral($referral_or_id);
        $referral_remote_id = $referral->custom;
    } else {
        $referral = $referral_or_id;
        $arguments = array(
            'amount' => $referral->amount,
            'currency' => $referral->currency,
            'description' => $referral->description,
            'reference' => $referral->reference,
            'context' => $referral->context,
            'status' => $referral->status,
        );
        $referral_remote_id = $referral->custom;
    }
    // Update id
    if(!is_numeric($referral_remote_id)){
        // Well, let's just don't update then
        return;
    }
    $query = http_build_query($arguments);
    // Ok, let's update
    $request = wpme_patch_to_affilaite_api(
        'referrals/' . $referral_remote_id . '?' . $query,
        null,
        'Error updating a referral.',
        3346
    );
    return $request['response']['code'] === 201 || $request['response']['code'] === 200;
}

/**
 * Track new visit
 */
add_action('affwp_post_insert_visit', function($id, $data){
  // Exit early
  if(wpme_aff_is_main_domain()){
      return;
  }
  // Get real affiliate ID from remote
  $current_affiliate_id = $data['affiliate_id'];
  $domain = wpme_api_get_base_url();
  $argsAff = wpme_api_get_api_headers_for('aff');
  $remote_user = wpme_api_sync_get_affiliate(
    affwp_get_affiliate_email($current_affiliate_id),
    $domain,
    $argsAff
  );
  // No user, no track
  if(!$remote_user){
    return;
  }
  // Args
  $arguments = array(
    'affiliate_id' => $remote_user->affiliate_id,
    'referral_id' => 0,
    'url' => $data['url'],
    'referrer' => '',
    'campaign' => '',
    'ip' => $data['ip'],
  );
  $query = http_build_query($arguments);
  // Ok, let's update
  $request = wpme_post_to_affilaite_api(
      'visits?' . $query,
      null,
      'Error inserting Visit',
      33499
  );
  return $request['response']['code'] === 201 || $request['response']['code'] === 200;
}, 10, 2);

/**
 * Is main domain
 */
function wpme_aff_is_main_domain(){
    $options = get_option('WPME_AFFI');
    if(!is_array($options)){
        return false;
    }
    if(isset($options['wpmeAffMainDomain']) && $options['wpmeAffMainDomain'] === 'off'){
        return false;
    } elseif(!isset($options['wpmeAffMainDomain'])){
        return false;
    }
    return true;
}

/**
 * .htaccess
 */

/**
 * Get .htaccess contents before being written to file
 *
 * Uncomment the first two lines and to go Settings > Permalinks to see the output.
 */
function wpme_inject_auth($rules)
{
    // Expand rules
    $rulesContainAuthCond = false;
    $rulesContainAuthRule = false;
    $rulesKeyBefore = false;
    $rulesExpanded = explode(PHP_EOL, $rules);
    $rulesToInject = array(
        'RewriteCond %{HTTP:Authorization} ^(.+)$',
        'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
    );
    // Check if auth is present
    foreach($rulesExpanded as $key => $value){
        if($value === 'RewriteEngine On'){
            $rulesKeyBefore = $key;
        }
        if (strpos($value, $rulesToInject[0]) !== false) {
            $rulesContainAuthCond = true;
        }
        if (strpos($value, $rulesToInject[1]) !== false) {
            $rulesContainAuthRule = true;
        }
    }
    // If not, add them in
    if(!$rulesContainAuthCond && !$rulesContainAuthRule){
        array_splice($rulesExpanded, $rulesKeyBefore + 1, 0, $rulesToInject);
        return implode($rulesExpanded, PHP_EOL);
    }
    return $rules;
}
add_filter('mod_rewrite_rules', 'wpme_inject_auth', 999, 1);

function crockford32_encode($data) {
    $chars = '0123456789abcdefghjkmnpqrstvwxyz';
    $mask = 0b11111;
    $dataSize = strlen($data);
    $res = '';
    $remainder = 0;
    $remainderSize = 0;

    for($i = 0; $i < $dataSize; $i++) {
        $b = ord($data[$i]);
        $remainder = ($remainder << 8) | $b;
        $remainderSize += 8;
        while($remainderSize > 4) {
            $remainderSize -= 5;
            $c = $remainder & ($mask << $remainderSize);
            $c >>= $remainderSize;
            $res .= $chars[$c];
        }
    }
    if($remainderSize > 0) {
        $remainder <<= (5 - $remainderSize);
        $c = $remainder & $mask;
        $res .= $chars[$c];
    }

    return $res;
}

function crockford32_decode($data) {
    $map = [
        '0' => 0,
        'O' => 0,
        'o' => 0,
        '1' => 1,
        'I' => 1,
        'i' => 1,
        'L' => 1,
        'l' => 1,
        '2' => 2,
        '3' => 3,
        '4' => 4,
        '5' => 5,
        '6' => 6,
        '7' => 7,
        '8' => 8,
        '9' => 9,
        'A' => 10,
        'a' => 10,
        'B' => 11,
        'b' => 11,
        'C' => 12,
        'c' => 12,
        'D' => 13,
        'd' => 13,
        'E' => 14,
        'e' => 14,
        'F' => 15,
        'f' => 15,
        'G' => 16,
        'g' => 16,
        'H' => 17,
        'h' => 17,
        'J' => 18,
        'j' => 18,
        'K' => 19,
        'k' => 19,
        'M' => 20,
        'm' => 20,
        'N' => 21,
        'n' => 21,
        'P' => 22,
        'p' => 22,
        'Q' => 23,
        'q' => 23,
        'R' => 24,
        'r' => 24,
        'S' => 25,
        's' => 25,
        'T' => 26,
        't' => 26,
        'V' => 27,
        'v' => 27,
        'W' => 28,
        'w' => 28,
        'X' => 29,
        'x' => 29,
        'Y' => 30,
        'y' => 30,
        'Z' => 31,
        'z' => 31,
    ];

    $data = strtolower($data);
    $dataSize = strlen($data);
    $buf = 0;
    $bufSize = 0;
    $res = '';

    for($i = 0; $i < $dataSize; $i++) {
        $c = $data[$i];
        if(!isset($map[$c])) {
            throw new \Exception("Unsupported character $c (0x".bin2hex($c).") at position $i");
        }
        $b = $map[$c];
        $buf = ($buf << 5) | $b;
        $bufSize += 5;
        if($bufSize > 7) {
            $bufSize -= 8;
            $b = ($buf & (0xff << $bufSize)) >> $bufSize;
            $res .= chr($b);
        }
    }
    return $res;
}
