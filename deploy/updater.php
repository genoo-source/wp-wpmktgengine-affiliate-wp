<?php
/**
 * Get Github Latest Version
 */

function wpme_get_github_version(){
  static $checked_version = null;
  if($checked_version !== null){
    return $checked_version;
  }
  $response = json_decode(wp_remote_retrieve_body(
    wp_remote_get(
      'https://raw.githubusercontent.com/genoo-source/wp-wpmktgengine-affiliate-wp/master/version.json'
    )
  ), true);
  if(!is_array($response) || !array_key_exists('version', $response)){
    throw new Error('No version returned');
  }
  $checked_version = $response['version'];
  return $response['version'];
}

/**
 * Updater init
 */
function wpme_updater_init($file){

  $GLOBALS['downloadLink'] = 'https://github.com/genoo-source/wp-wpmktgengine-affiliate-wp/archive/master.zip';
  $GLOBALS['plugin'] = null;
  $GLOBALS['basename'] = null;
  $GLOBALS['active'] = null;
  static $version = null;

  /**
   * Updater
   */
  add_action('admin_init', function() use ($file) {
    //  Get the basics
    $GLOBALS['plugin'] = get_plugin_data($file);
    $GLOBALS['basename'] = plugin_basename($file);
    $GLOBALS['active'] = is_plugin_active($GLOBALS['basename']);
  });

  // Add update filter
  add_filter('site_transient_update_plugins', function($transient) use ($file, $version) {
    if($transient && property_exists( $transient, 'checked') ) {
      if( $checked = $transient->checked && isset($GLOBALS['plugin'])) { 
        $version = $version === null ? wpme_get_github_version() : $version;
        $out_of_date = version_compare($version, $checked[$GLOBALS['basename']], 'gt' );
        if($out_of_date){
          $slug = current(explode('/', $GLOBALS['basename']));
          $plugin = array(
            'url' => isset($GLOBALS['plugin']['PluginURI']) ? $GLOBALS['plugin']['PluginURI'] : '',
            'slug' => $slug,
            'package' => $GLOBALS['downloadLink'],
            'new_version' => $version,
          );
          $transient->response[$GLOBALS['basename']] = (object)$plugin; 
        }
      }
    }
    return $transient;
  }, 10, 1 );

  // Add pop up filter
  add_filter('plugins_api', function($result, $action, $args) use ($file, $version){
		if( ! empty( $args->slug ) ) { // If there is a slug
			if( $args->slug == current( explode( '/' , $GLOBALS['basename']))) { // And it's our slug
        $version = $version === null ? wpme_get_github_version() : $version;
        // Set it to an array
				$plugin = array(
					'name'				=> $GLOBALS['plugin']["Name"],
					'slug'				=> $GLOBALS['basename'],
					'requires'	  => '',
					'tested'			=> '',
					'rating'			=> '100.0',
					'num_ratings'	=> '10',
					'downloaded'	=> '134',
					'added'				=> '2016-01-05',
					'version'			=> $version,
					'author'			=> $GLOBALS['plugin']["AuthorName"],
					'author_profile'	=> $GLOBALS['plugin']["AuthorURI"],
					'last_updated'		=> '',
					'homepage'			=> $GLOBALS['plugin']["PluginURI"],
					'short_description' => $GLOBALS['plugin']["Description"],
					'sections'			=> array(
						'Description'	=> $GLOBALS['plugin']["Description"],
						'Updates'		=> $version,
					),
					'download_link'		=> $GLOBALS['downloadLink'],
				);
				return (object)$plugin;
			}
		}
		return $result;
  }, 10, 3);

  // Add install filter
	add_filter('upgrader_post_install', function($response, $hook_extra, $result) use($file) {
    global $wp_filesystem;
    $install_directory = plugin_dir_path($file);
    $wp_filesystem->move( $result['destination'], $install_directory);
    $result['destination'] = $install_directory;
    if ($GLOBALS['active']) { // If it was active
			activate_plugin($GLOBALS['basename']); // Reactivate
		}
  }, 10, 3 );
}
