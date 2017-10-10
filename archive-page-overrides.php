<?php
/**
 * Plugin Name: WordPress Archive Page Overrides
 * Plugin URI: http://wordpress.org/plugins/archive-page-overrides/
 * Description: A plugin which extends WordPress' Settings > Reading page to allow CMS pages to take the place of core WordPress functionality; Search pages, 404 pages and post type archive pages.
 * This is most useful when using things like ACF's Flexible Content to allow for modular pages.
 * To use this plugin, access the $post global variable present in your template files; 404.php, search.php, {custom-post-type}-archive.php
 * There's a fair amount of database CRUD going on here so I'd recommend only using this behind a decent full page cache.
 * Author: Aaron Dicks
 * Version: 1.0
 * Author URI: https://www.impression.co.uk
 *
 * @package archive-page-overrides
 * @version 1.0
 */

/**
 * TODO
 * -- Add plugin text domain onto __('') filter
 * --
 */


// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

class WPPageOverrides {

  /**
   * Class __construct
   */
	public function __construct() {
    add_filter( 'admin_init', array( &$this, 'register_setting_fields' ) );
    add_action( 'admin_notices', array( &$this, 'page_admin_notices' ) );
    add_action( 'template_redirect', array( &$this, 'inject_override_ids') );
    add_filter( 'template_redirect', array( &$this, 'noindex_actual_page_urls' ) );
    add_filter( 'display_post_states', array( &$this, 'insert_post_states' ) );
    if ( function_exists('get_field') ) {
      add_filter( 'acf/location/rule_values/page_type', array( &$this, 'acf_add_page_types') );
      add_filter( 'acf/location/rule_match/page_type', array( &$this, 'acf_match_page_types'), 5, 3 );
    }
    register_deactivation_hook( __FILE__, array( &$this, 'deactivate' ) );
	}

  public function insert_post_states($states) {
    global $post;
    if (!isset($post) || !isset($post->ID))
      return $states;

    $all_options = wp_load_alloptions();
    if (in_array($post->ID, $all_options)) {
      $key = array_search($post->ID, $all_options);
      switch ($key) {
        case 'wppageoverrides_404_id':
          $states[] = __('404 page');
          break;
        case 'wppageoverrides_search_id':
          $states[] = __('Search page');
          break;
        default:
          preg_match( '/wppageoverrides_(.*)_id/', $key, $matches);
          if (empty($matches))
            return $states;

          $pt = get_post_type_object($matches[1]);
          $states[] = $pt->label.' '.__('page');
          break;
      }
    }
    return $states;
  }

  /**
   * [inject_override_ids description]
   * @return [type] [description]
   */
  public function inject_override_ids() {
    global $wpdb;
    global $post;

    if (is_404()) {
      $prepared_query = $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", "wppageoverrides_404_id");
      $page_id = $wpdb->get_var( $prepared_query );
      if ($page_id) {
        $post = get_post($page_id);
      }
    }

    if (is_search()) {
      $prepared_query = $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", "wppageoverrides_search_id");
      $page_id = $wpdb->get_var( $prepared_query );
      if ($page_id) {
        $post = get_post($page_id);
      }
    }

    if (is_post_type_archive() && !is_post_type_archive(['post'])) {
      $post_type = get_query_var('post_type');
      $prepared_query = $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", "wppageoverrides_{$post_type}_id");
      $page_id = $wpdb->get_var( $prepared_query );
      if ($page_id) {
        $post = get_post($page_id);
      }
    }

    return;
  }

  /**
   * [noindex_actual_page_urls description]
   * @return [type] [description]
   */
  public function noindex_actual_page_urls() {
    global $wpdb;
    global $post;

    if (!isset($post) || !isset($post->ID))
      return;

    $prepared_query = $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s AND option_value = %d", '%wppageoverrides_%', $post->ID);
    $results = $wpdb->get_var( $prepared_query );

    if (!$results)
      return;

    add_action( 'wp_head', array( &$this, 'noindex_this_page' ) );
    // For if you have Yoast WordPress SEO installed
    add_filter( 'wpseo_robots', '__return_null', 10, 1 );
  }

  public function noindex_this_page() {
    echo '<meta name="robots" content="noindex" />';
    header( "X-Robots-Tag: noindex", true );
  }

  /**
   * [page_admin_notices description]
   * @return [type] [description]
   */
  public function page_admin_notices() {
    /**
     * We're only intersted in page edit screens with a post ID set
     */
    $screen = get_current_screen();
    if (!$screen->post_type == "page" || !$screen->parent_base =="edit" || !isset($_GET['post']))
      return;

    /**
     * Is there a better way of getting these IDs?
     * I think this is escaped and prepared enough?
     */
    global $wpdb;
    $page_id = intval($_GET['post']);
    $prepared_query = $wpdb->prepare( "SELECT option_name FROM $wpdb->options WHERE option_name LIKE %s AND option_value = %d", '%wppageoverrides_%', $page_id);
    $results = $wpdb->get_var( $prepared_query );

    if (!$results)
      return;

    preg_match( '/wppageoverrides_(.*)_id/', $results, $matches);

    if (!$matches[1])
      return;

    switch ($matches[1]) {
      case '404':
        $message_partial = __("404: Page not found");
        break;
      case 'search':
        $message_partial = __("Search Results");
        break;
      default:
        $pt = get_post_type_object($matches[1]);
        $message_partial = __('all').' '.$pt->label;
        break;
    }

    $class = 'notice notice-warning is-dismissable';
  	$message = __( 'You are currently viewing the page you have set to display ' );

  	printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message.$message_partial ) );
  }

  public function register_setting_fields() {
    add_settings_section( 'wppageoverrides_settings-area', __('CMS page overrides'), array(&$this, 'settings_section_html'), 'reading' );

    /**
     * Public custom post types
     */
    $public_post_types = get_post_types( [
      'public'   => true,
      'has_archive' => true,
      '_builtin' => false
    ], 'objects' );

    $post_types = [];
    foreach ($public_post_types as $pt) {
      $post_types[] = ['name' => $pt->name, 'label' => $pt->label.' '.__('archive') ];
    }

    /**
     * Add in 404 and search results page
     */
    $settings_objects = array_merge([
      [
        'name'    => '404',
        'label'   => __('404: Page not found')
      ], [
        'name'    => 'search',
        'label'   => __('Search results')
      ]
    ], $post_types);

    $settings_objects = apply_filters('wppageoverrides_settings_objects', $settings_objects);

    foreach ($settings_objects as $settings_object) {
      register_setting( 'reading', "wppageoverrides_{$settings_object['name']}_id", 'intval' );
      add_settings_field( "wppageoverrides_{$settings_object['name']}_id", __($settings_object['label']) , function() use ($settings_object) {$this->fields_html_custom($settings_object);} , 'reading', 'wppageoverrides_settings-area', ['label_for' => "wppageoverrides_{$settings_object['name']}_id"] );
    }

  }

  public function settings_section_html() {
    // TODO: Allow translation of this.
    echo wpautop("These settings allow for CMS pages to take the place of core WordPress functionality; Search pages, 404 pages and post type archive pages.\nThis is most useful when using things like ACF's Flexible Content to manage page headings and banners.");
  }

  public function fields_html_custom($object) {
    wp_dropdown_pages([
      'selected'              => get_option("wppageoverrides_{$object['name']}_id", 0),
      'name'                  => "wppageoverrides_{$object['name']}_id",
      'id'                    => "wppageoverrides_{$object['name']}_id",
      'show_option_none'      => '-- '.__('Please select').' --'
    ]);
    echo "<p class='description' id='wppageoverrides_{$object['name']}_id-description'>";
    echo sprintf(__("Override the default %s page with one that you've built in WordPress. Just select it here."), $object['label']);
    echo "</p>";
  }

  public function deactivate() {
    if ( ! current_user_can( 'activate_plugins' ) )
      return;
    $plugin = isset( $_REQUEST['plugin'] ) ? $_REQUEST['plugin'] : '';
    check_admin_referer( "deactivate-plugin_{$plugin}" );

    global $wpdb;
    $prepared_query = $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name LIKE %s", '%wppageoverrides_%');
    $wpdb->get_results( $prepared_query );
    return;
  }

  public function acf_match_page_types($match, $rule, $options){
    global $post; global $wpdb;
    if (empty($post->ID))
      return false;

    $prepared_query = $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", sprintf("wppageoverrides_%s_id", $rule['value']));
    $page_id = $wpdb->get_var( $prepared_query );

    if($rule['operator'] == "=="){
    	$match = ( $post->ID == $page_id );
    }
    elseif($rule['operator'] == "!="){
    	$match = ( $post->ID  != $page_id );
    }

    return $match;
  }

  public function acf_add_page_types($choices){
    $public_post_types = get_post_types( [
      'public'   => true,
      '_builtin' => false
    ], 'objects' );

    foreach ($public_post_types as $pt) {
      $choices[$pt->name] = $pt->label.' '.__('Archive');
    }

    $choices['404'] = '404: Page not found';
    $choices['search'] = 'Search results';
    return $choices;
  }

}

$WPPageOverrides = new WPPageOverrides();
