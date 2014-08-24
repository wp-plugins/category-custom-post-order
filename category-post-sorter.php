<?php
/*
Plugin Name: Category Custom Post Order
Version: 1.3.1
Plugin URI: http://potrebka.pl/
Description: Order post as you want.
Author: Piotr Potrebka
Domain Path: /languages/
License: GPL2

Copyright 2013 Piotr Potrebka - contact@potrebka.pl)

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
new category_custom_post_order();

class category_custom_post_order {
	// all - display order link everywhere
	public $sortlink_in = array ( 'all', 'category');

	function __construct() {
		load_plugin_textdomain( 'cps', false, 'category-post-sorter/languages' );
		add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
		add_filter( 'tag_row_actions', array( &$this, 'add_cat_order_link'), 10, 2 );
		add_filter( 'posts_request', array( &$this, 'sort_request'), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ), 1 );
		add_action( 'parse_tax_query', array( $this, 'tax_query' ), 1 );
    }
	
    public function tax_query( $query ) {
		if( isset( $query->tax_query->queries[0]['include_children'] ) AND ( $query->is_category() OR $query->is_tax() ) )
			$query->tax_query->queries[0]['include_children'] = false;
	}
	
    public function pre_get_posts( $query )
    {
		if( is_admin() OR !$query->is_main_query() ) return;
		
		$tax_slug =  isset( $query->tax_query->queries[0]['terms'][0] ) ? $query->tax_query->queries[0]['terms'][0] : 0;
		$taxonomy =  isset( $query->tax_query->queries[0]['taxonomy'] ) ? $query->tax_query->queries[0]['taxonomy'] : 0;
		$field = is_int( $tax_slug ) ? 'term_id' : 'slug';
		if( $tax_slug AND $taxonomy ) {
			$term = get_term_by( $field, $tax_slug, $taxonomy );
			if( !isset( $term->term_id ) ) return;
			$term_id = $term->term_id;
			$tax_slug = $term->slug;
		} else {
			return;
		}
		
		$active = get_option( 'post_sorter_'.$term_id );
        if ( $active  AND ( $query->is_category() OR $query->is_tax() ) ) {
			$query->set('meta_key', 'sort_'.$term_id);
			$query->set('meta_type', 'NUMERIC');
			$query->set('orderby', 'meta_value');
			$query->set('order', 'ASC');
			$query->set('meta_query', array(
				'relation' => 'OR',
				array(
					'key' => 'sort_'.$term_id,
					'compare' => 'NOT EXISTS'
				)
			));
			
        }
    }

	function sort_request( $query ) {
		global $wpdb;
		if( !preg_match('/sort_[0-9]{1,3}/i', $query) OR !is_admin() ) return $query;
		$query = preg_replace('/INNER JOIN '.$wpdb->postmeta.'/i', "LEFT JOIN {$wpdb->postmeta}", $query);
		return $query;
	}
	
	function admin_menu() {
		$page_hook_suffix = add_submenu_page( null, __('Order posts', 'post-sorter'), __('Order posts', 'post-sorter'), 'manage_options', 'sort-page', array( $this, 'admin_page' ), 0 );
		add_action('admin_print_scripts-' . $page_hook_suffix, array( $this, 'admin_scripts' ) );
	}
	
    function admin_scripts() {
        wp_enqueue_script( 'jquery-ui-sortable' );
    }

	function add_cat_order_link($actions, $term)
	{
		if( !isset( $term->term_id ) OR !isset( $term->taxonomy ) ) return $actions;
		if( ( !empty( $term->taxonomy ) AND in_array( $term->taxonomy, $this->sortlink_in) ) OR in_array( 'all', $this->sortlink_in) ) {
			
			$actions['order_link'] = '<a href="'.admin_url('edit.php?page=sort-page&taxonomy='.$term->taxonomy.'&term_id='.$term->term_id).'">' . __('Order', 'cps') . '</a>';
		}
		return $actions;
	}

	function admin_page() {

		$term_id = isset($_GET['term_id']) ? $_GET['term_id'] : 0;
		$taxonomy = isset($_GET['taxonomy']) ? $_GET['taxonomy'] : 0;
		$term = get_term_by('id', $term_id, $taxonomy );
		$term_link = get_term_link( $term );
		if( !isset( $term->name ) ) return;
		if ( isset( $_POST['sort'] ) AND is_array($_POST['sort']) && check_admin_referer( 'save_sort', 'category_custom_post_order' ) ) {
			
			foreach($_POST['sort'] as $order=>$post_id) {
				$meta_key = 'sort_' . $term_id;
				if( isset( $_POST['submit'] )) {
					add_post_meta( $post_id, $meta_key, $order, true ) || update_post_meta( $post_id, $meta_key, $order );
					add_option( 'post_sorter_'.$term_id, 1 );
				}
				if( isset( $_POST['remove'] )) {
					delete_post_meta( $post_id, $meta_key );
					delete_option( 'post_sorter_'.$term_id );
				}
				
			}
		}
		$active = get_option( 'post_sorter_'.$term_id );

		if( $active ) {
			$args = array(
				'tax_query' => array('relation' => 'AND', array( 'taxonomy'=> $taxonomy, 'field'=>'slug', 'terms'=>$term->slug, 'include_children' => false)),
				'posts_per_page' => -1,
				'meta_key' => 'sort_'.$term->term_id,
				'meta_type' => 'NUMERIC',
				'orderby' => 'meta_value',
				'order' => 'ASC',
				'meta_query' => array('relation' => 'OR', array('key' => 'sort_'.$term->term_id, 'compare' => 'NOT EXISTS'))
			);
		} else {
			$args = array(
				'tax_query' => array('relation' => 'AND',array('taxonomy'=> $taxonomy, 'field'=>'slug', 'terms'=>$term->slug, 'include_children' => false)),
				'posts_per_page' => -1
			);
		}
		$query = new WP_Query($args);
		?>
			<div class="wrap"><h2><?php _e('Order posts', 'cps'); ?></h2>
			<form method="post">
			<?php wp_nonce_field( 'save_sort','category_custom_post_order' ); ?>
			<script>
			jQuery(function() {
				jQuery("tbody").sortable();
			});
			</script>
				<table class="wp-list-table widefat plugins">
					<thead>
						<tr>
							<th scope="col"><strong><?php _e('Category:', 'cps'); ?> <a href="<?php echo $term_link; ?>" target="_blank"><?php echo $term->name; ?></a></strong></th>
						</tr>
					</thead>
					<tbody id="the-list">
						<?php if( $query->have_posts() ): ?>
							<?php while ( $query->have_posts() ) : $query->the_post(); ?>
							<?php $order = get_post_meta( get_the_ID(), 'sort_' . $term_id, true); ?>
							
							<tr>
								<td style="border-bottom: 1px solid #EEE; cursor:move;">
									<input type="hidden" name="sort[]" value="<?php the_ID(); ?>" />[<?php echo $order; ?>] <?php the_title(); ?> (<?php echo get_post_status(); ?>)
								</td>
							</tr>
							<?php endwhile; ?>
						<?php else: ?>
							<tr><td><?php _e('No posts', 'cps'); ?></td></tr>
						<?php endif; ?>
					</tbody>
				</table>
				<p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Reorder', 'cps'); ?>"  /><input type="submit" name="remove" id="submit" class="button button-secondary" value="<?php _e('Remove order', 'cps'); ?>"  /></p>
				</form>
			</div>
		<?php
	}
}