<?php
// Utilities
require_once 'api-sync.php';

// Class to auto get api
if(!class_exists('Genoo_Wpme_Api')){
    /**
     * Class Genoo_Wpme_Api_Base
     */
    class Genoo_Wpme_Api_Base {

        /**
         * @var mixed
         */
        public $api;
        /**
         * @var bool
         */
        public $isGenoo;

        /**
         * Genoo_Wpme_Api_Base constructor.
         */
        public function __construct($api = null)
        {
            // Passed?
            if(!is_null($api)){
                $this->api = $api;
                return;
            }
            // Is in global?
            if(isset($GLOBALS['WPME_API']) && is_object($GLOBALS['WPME_API'])){
                $this->api = $GLOBALS['WPME_API'];
            } else {
                if(class_exists('\WPME\ApiFactory') && class_exists('\WPME\RepositorySettingsFactory')){
                    $repo = new \WPME\RepositorySettingsFactory();
                    $this->api = new \WPME\ApiFactory($repo);
                    if(class_exists('\Genoo\Api')){
                        $this->isGenoo = TRUE;
                    }
                } elseif(class_exists('\Genoo\Api') && class_exists('\Genoo\RepositorySettings')){
                    $repo = new \Genoo\RepositorySettings();
                    $this->api = new \Genoo\Api($repo);
                    if(class_exists('\Genoo\Api')){
                        $this->isGenoo = TRUE;
                    }
                } elseif(class_exists('\WPMKTENGINE\Api') && class_exists('\WPMKTENGINE\RepositorySettings')){
                    $repo = new \WPMKTENGINE\RepositorySettings();
                    $this->api = new \WPMKTENGINE\Api($repo);
                }
            }
        }

        /**
         * @return bools
        */
        public function canContinue()
        {
            return is_object($this->api);
        }

        /**
         * @param $action
         * @param null $params
         * @param null $url
         * @return object|string
         * @throws \Exception
         */
        public function get($action, $params = NULL, $url = NULL){
            return $this->request('GET', $action, $params, $url);
        }

        /**
         * @param $action
         * @param null $params
         * @param null $url
         * @return object|string
         * @throws \Exception
         */
        public function post($action, $params = NULL, $url = NULL){
            return $this->request('POST', $action, $params, $url);
        }

        /**
         * @param $action
         * @param null $params
         * @param null $url
         * @return object|string
         * @throws \Exception
         */
        public function put($action, $params = NULL, $url = NULL){
            return $this->request('PUT', $action, $params, $url);
        }

        /**
         * @param $action
         * @param null $params
         * @param null $url
         * @return object|string
         * @throws \Exception
         */
        public function delete($action, $params = NULL, $url = NULL){
            return $this->request('DELETE', $action, $params, $url);
        }

        /**
         * Make request
         *
         * @param string $type
         * @throws \Exception
         */
        public function request($type = 'GET', $action, $params = NULL, $url = NULL)
        {
            if (!method_exists($this->api, 'callCustom')) {
                throw new Exception('No callCustom in API object');
            }
            return $this->api->callCustom($action, $type, $params, $url);
        }
    }
}


/**
 * Class GenooWpmeAffilaiteWP
 */
class GenooWpmeAffilaiteWP extends Genoo_Wpme_Api_Base
{
    /**
     * Create an affiliate
     *
     * @param null $first_name
     * @param null $last_name
     * @param null $email
     * @return bool
     */
    public function createAffilliate(
        $first_name = null,
        $last_name = null,
        $email = null,
        $status = 'active'
    ){
        try {
            // This calls an API
            $this->post('/affiliates/' . urlencode($email), (object)array(
                'first_name' => (string)$first_name,
                'last_name' => (string)$last_name,
                'email' => $email,
                'status' => $status,
                'hash' => wpme_encrypt_string($email),
                'affiliate_id' => wpme_encrypt_string($email),
                'leadtype' => (string)getAffiliateWpLeadType()
            ));
            // Created?
            return $this->api->http->getResponseCode() === 201;
        } catch(Exception $e){
            // Not created, some kind of an issue
            return false;
        }
    }

    /**
     * Update Ref Var
     */
    public function updateRefVariable($var = '')
    {
        try {
            $ch = curl_init();
            if(defined('GENOO_DOMAIN')){
                curl_setopt($ch, CURLOPT_URL, 'https:' . GENOO_DOMAIN . '/api/rest/affiliateref/' . $var);
            } else {
                curl_setopt($ch, CURLOPT_URL, 'https:' . WPMKTENGINE_DOMAIN . '/api/rest/affiliateref/' . $var);
            }
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("X-API-KEY: " . $this->api->key));
            $resp = curl_exec($ch);
            // Created?=
            return true;
        } catch(Exception $e){
            // Not created, some kind of an issue
            return false;
        }
    }

    /**
     * Get lead ref
     */
    public function getLeadRef($email = '')
    {
      try {
        $result = $this->get('/leadbyemail/' . urlencode($email));
        $result = is_array($result) ? $result[0] : false;
        if(!$result){
          return false;
        }
        // Lead exists, let's see if data is in
        if(
          is_string($result->c00referred_by_affiliate_id_date) 
          && !empty($result->c00referred_by_affiliate_id_date)
          && is_string($result->c00referred_by_affiliate_id)
          && !empty($result->c00referred_by_affiliate_id)
        ){
          return array(
            'c00referred_by_affiliate_id_date' => $result->c00referred_by_affiliate_id_date,
            'c00referred_by_affiliate_id' => $result->c00referred_by_affiliate_id,
            'c00sold_by_affiliate_id' => $result->c00sold_by_affiliate_id
          );
        }
        return false;
      } catch (\Exception $e){
        return false;
      }
    }

    /**
     * @param null $first_name
     * @param null $last_name
     * @param null $email
     * @param string $status
     * @return bool
     * @throws \Exception
     */
    public function updateAffiliate(
        $first_name = null,
        $last_name = null,
        $email = null,
        $status = 'active'
    ){
        return $this->createAffilliate(
          $first_name,
          $last_name,
          $email,
          $status
        );
    }

    /**
     * @param $email
     * @return bool|mixed
     * @throws \Exception
     */
    public function getAffiliate($email){
        if($email === null){
            throw new Exception('Id of affialite is required');
        }
        try {
            return $this->get('/affiliates/' . $email);
        } catch (Exception $e){
            return false;
        }
    }

    /**
     * @return bool
     */
    public function isEnabled(){
        try {
            $this->get('/affiliateenable');
            return $this->api->http->getResponseCode() === 200;
        } catch (Exception $e){
            return false;
        }
    }
}
