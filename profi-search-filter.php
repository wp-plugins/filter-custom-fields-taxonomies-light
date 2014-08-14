<?php
	/*
	Plugin Name: Filter Custom Fields & Taxonomies Light
	Plugin URI: http://www.profisearchform.com/
	Description: With this Plugin, you can easily create AJAX Search Filters, which enables a more detailed search using Taxonomies and Postmeta data
	Tags: search,filter,postmeta,taxonomies,ajax
	Version: 1.00
	Text Domain: sf
	Author: Websupporter.net
	Author URI: http://www.websupporter.net/
	License: GPLv2 or later
	License URI: http://www.gnu.org/licenses/gpl-2.0.html
	*/
	
	define( 'SF_CURRENT_VERSION', '1.00' );
	if( !session_id() )
		session_start();
		
	define( 'SF_URL', plugins_url( '', __FILE__ ) . '/' );
	define( 'SF_DIR', dirname( __FILE__ ) . '/' );
	define( 'HOME_URL', get_bloginfo( 'url' ) );
	define( 'HOME_NAME', get_bloginfo( 'name' ) );
	
	require_once( SF_DIR . 'admin/admin.php' );
	require_once( SF_DIR . 'ajax.php' );
	require_once( SF_DIR . 'includes/wpml-functions.php' );
	
	function sf_textdomain() {
		$plugin_dir = basename( dirname( __FILE__ ) ) . '/res/lang/';
		load_plugin_textdomain( 'sf', false, $plugin_dir );
	}
	add_action('plugins_loaded', 'sf_textdomain');
	
	add_action( 'wp_head', 'sf_head', 1 );
	function sf_head(){
		$settings = get_option( 'search-filter-settings' );
		if( !isset( $settings['style'] ) || $settings['style'] == '' )
			wp_register_style( 'sf-style', SF_URL . 'res/style.css' );
		else
			wp_register_style( 'sf-style', SF_URL . 'res/' . $settings['style'] . '.css');
		wp_enqueue_style( 'sf-style' );	
		
		
		wp_enqueue_script('jquery');
		wp_enqueue_script('jquery-ui-slider');
		wp_register_script( 'sf-script', SF_URL . 'res/sf.js' );
		wp_enqueue_script( 'sf-script' );
		
		?>
		<script>var sf_ajax_root = '<?php echo admin_url('admin-ajax.php'); ?>'</script>
		<?php
	}
	
	
	function insert_searchform( $id ){
		$attr = array( 'id' => $id );
		
		ob_start();
		require( SF_DIR . 'includes/shortcode.php' );
		$output_string=ob_get_contents();
		ob_end_clean();
		echo $output_string;
	}
	
	add_shortcode( 'search-form', 'sf_init_searchform' );
	function sf_init_searchform( $attr ){
		ob_start();
		require( SF_DIR . 'includes/shortcode.php' );
		$output_string=ob_get_contents();
		ob_end_clean();
		return $output_string;
	}

	function get_all_postmetas_from_post_type( $post_type ){
		global $wpdb;
		if( !is_array( $post_type ) )
			$post_type = array( $post_type );
		
		$data = array();
		$sql = $wpdb->prepare( "
			SELECT $wpdb->postmeta.`meta_key`, $wpdb->postmeta.`meta_value`
			FROM $wpdb->postmeta, $wpdb->posts
			WHERE $wpdb->posts.`post_status` = 'publish' && $wpdb->posts.`post_type` IN (".implode(', ', array_fill(0, count($post_type), '%s')).") && $wpdb->postmeta.`post_id` = $wpdb->posts.`ID`
			group by $wpdb->postmeta.`meta_key`
		", $post_type );
		$wpdb->query( $sql );
		$no_key = array( '_edit_last', '_edit_lock', '_thumbnail_id' );		
		foreach($wpdb->last_result as $k => $v){
			if( !in_array( $v->meta_key , $no_key ) && !is_array( maybe_unserialize( $v->meta_value ) ) )
				$data[$v->meta_key] =   $v->meta_value;
		};
		return $data;
	}
	
	function get_all_post_taxonomies( $post_type ) {
		$taxonomies = array();
		if( !is_array( $post_type ) )
			$post_type = array( $post_type );
		foreach( $post_type as $p )
			$taxonomies = array_merge( $taxonomies, get_object_taxonomies($p, 'objects') ); 
		return (array) $taxonomies; 
	}
	
	function get_postmeta_values( $meta_key ){
		global $wpdb;
		
		$sql = $wpdb->prepare( "
			SELECT $wpdb->postmeta.`meta_key`, $wpdb->postmeta.`meta_value`
			FROM $wpdb->postmeta, $wpdb->posts
			WHERE 
				$wpdb->posts.`post_status` = 'publish' && 
				$wpdb->postmeta.`post_id` = $wpdb->posts.`ID` &&
				$wpdb->postmeta.`meta_key` = '%s' 
			group by 
				$wpdb->postmeta.`meta_value` order by $wpdb->postmeta.`meta_value` asc", 
			$meta_key );
		$res = $wpdb->get_results( $sql );
		return $res;
	}
	
	function sf_content_filter_join( $join_paged_statement, &$wp_query ) {
		global $wpdb;
		$metas = $wp_query->get( 'sf-meta' );
		if( isset( $metas ) && is_array( $metas ) && count( $metas ) > 0 ){
			foreach( $wp_query->get( 'sf-meta' ) as $meta => $val ){
				$join_paged_statement .= " LEFT JOIN " . $wpdb->prefix . "postmeta as " . md5( $meta ) . " ON ( " . md5( $meta ) . ".post_id = " . $wpdb->prefix . "posts.ID ) ";
			}
		}
		return $join_paged_statement;
	}
	
	function sf_content_filter( $sf_where, &$wp_query ){
        global $wpdb;
		
		if( $wp_query->get( 'sf-title' ) || $wp_query->get( 'sf-content' )  || $wp_query->get( 'sf-excerpt' ) ):
			
			$sf_add_where = ' AND (';
			if ( $search_term = $wp_query->get( 'sf-title' ) ) 
				$sf_add_where .= '' . $wpdb->posts . '.post_title LIKE \'%' . esc_sql( like_escape( $search_term ) ) . '%\'';
        
			if ( $search_term = $wp_query->get( 'sf-content' ) ){ 
				if( $sf_add_where != ' AND (' )
					$sf_add_where .= ' OR ';
				$sf_add_where .= '' . $wpdb->posts . '.post_content LIKE \'%' . esc_sql( like_escape( $search_term ) ) . '%\'';
			}
			
			if ( $search_term = $wp_query->get( 'sf-excerpt' ) ){
				if( $sf_add_where != ' AND (' )
					$sf_add_where .= ' OR ';
				$sf_add_where .= '' . $wpdb->posts . '.post_excerpt LIKE \'%' . esc_sql( like_escape( $search_term ) ) . '%\'';
			}
			
			$metas = $wp_query->get( 'sf-meta' );
			if( isset( $metas ) && is_array( $metas ) && count( $metas ) > 0 ){
				foreach( $wp_query->get( 'sf-meta' ) as $meta => $search_term ){
					preg_match( '^meta\[(.*)\]^', $meta, $match );
					if( $sf_add_where != ' AND (' )
						$sf_add_where .= ' OR ';
					$sf_add_where .= ' (' .md5( $meta ) . '.meta_value LIKE \'%' . esc_sql( like_escape( $search_term ) ) . '%\' && ' .md5( $meta ) . '.meta_key = \'' . $match[1] . '\') ';
				}
			}
			
			$sf_add_where .= ' ) ';
			$sf_where .= $sf_add_where;
	   endif;
       return $sf_where;
        
	}
	
	function order_terms_hierarchical_array( $terms, $parent = 0 ){
		$tmp_terms = array();
		foreach( $terms as $term ):
			if( $term->parent == $parent ):
				$term->children = order_terms_hierarchical_array( $terms, $term->term_id );
				$tmp_terms[] = $term;
			endif;
		endforeach;
		return $tmp_terms;
	}
	
	function flatten_terms_hierarchical_array( $terms, $symbol, $status = 0 ){
		$tmp_terms = array();
		foreach( $terms as $term ):
			if( $status == 1 ):
				$term->name = $symbol . ' ' . $term->name;
			endif;
			$tmp_terms[] = $term;
			
			if( count( $term->children ) > 0 ):
				if( $status == 1 )
					$s = $symbol . $symbol;
				else
					$s = $symbol;
				$tmp_terms = array_merge( $tmp_terms, flatten_terms_hierarchical_array( $term->children, $s, 1 ) ); 
			endif;
		endforeach;
		return $tmp_terms;
	}
	
	function order_terms_hierarchical( $terms, $symbol ){
		$terms = order_terms_hierarchical_array( $terms );
		$terms = flatten_terms_hierarchical_array( $terms, $symbol );		
		return $terms;
	}
?>