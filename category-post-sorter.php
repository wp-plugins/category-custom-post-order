<?php
/*
Plugin Name: Category Custom Post Order
Version: 1.3.3
Plugin URI: http://potrebka.pl/
Description: Order post as you want.
Author: Piotr Potrebka
License: GPL2

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

	public function __construct() {
		load_plugin_textdomain( 'cps', false, basename( dirname( __FILE__ ) ) . '/languages' );
		add_action( 'admin_menu', array( &$this, 'admin_menu' ) );
		add_filter( 'tag_row_actions', array( &$this, 'add_cat_order_link'), 10, 2 );
		add_action( 'parse_tax_query', array( $this, 'tax_query' ), 1 );
		add_filter( 'posts_clauses', array( $this, 'posts_clauses' ), 2, 2 );
		add_filter( 'posts_clauses', array( $this, 'admin_posts_clauses' ), 20, 2 );
		add_filter( 'admin_init', array( $this, 'save' ), 20, 2 );
		
		$this->term_id = isset($_GET['term_id']) ? $_GET['term_id'] : 0;
		$this->taxonomy = isset($_GET['taxonomy']) ? $_GET['taxonomy'] : 0;
		
    }
	
    public function tax_query( $query ) {
		if( isset( $query->tax_query->queries[0]['include_children'] ) AND ( $query->is_category() OR $query->is_tax() ) )
			$query->tax_query->queries[0]['include_children'] = false;
	}
	
	public function posts_clauses( $clauses, $query ) {
		global $wpdb;
		if( is_admin() OR !$query->is_main_query() ) return $clauses;
		$term_id = $query->query_vars['cat'];
		$active = get_option( 'post_sorter_'.$term_id );
        if ( $active  AND $term_id ) {
			$clauses['join'] .= " LEFT JOIN $wpdb->postmeta sort ON ($wpdb->posts.ID = sort.post_id AND sort.meta_key = 'sort_".$term_id."')";
			$clauses['where'] .= " AND ( sort.meta_key = 'sort_".$term_id."' OR sort.post_id IS NULL )";
			$clauses['orderby'] = " CAST(sort.meta_value AS SIGNED) ";
		}
		return $clauses;
	}
	
	public function admin_posts_clauses( $clauses, $query ) {
		global $wpdb;
		if( !$this->term_id OR !$this->taxonomy ) return $clauses;
		$clauses['join'] .= "LEFT JOIN $wpdb->postmeta sort ON ($wpdb->posts.ID = sort.post_id AND sort.meta_key = 'sort_".$this->term_id."')";
		$clauses['where'] .= "AND ( sort.meta_key = 'sort_".$this->term_id."' OR sort.post_id IS NULL )";
		$clauses['orderby'] = "CAST(sort.meta_value AS SIGNED), $wpdb->posts.post_date DESC";
		return $clauses;
	}
	
	public function admin_menu() {
		$page_hook_suffix = add_submenu_page( null, __('Order posts', 'post-sorter'), __('Order posts', 'post-sorter'), 'manage_options', 'sort-page', array( $this, 'admin_page' ), 0 );
		add_action('admin_print_scripts-' . $page_hook_suffix, array( $this, 'admin_scripts' ) );
	}
	
    public function admin_scripts() {
        wp_enqueue_script( 'jquery-ui-sortable' );
    }

	public function add_cat_order_link($actions, $term)
	{
		if( !isset( $term->term_id ) OR !isset( $term->taxonomy ) ) return $actions;
		if( ( !empty( $term->taxonomy ) AND in_array( $term->taxonomy, $this->sortlink_in) ) OR in_array( 'all', $this->sortlink_in) ) {
			$actions['order_link'] = '<a href="'.admin_url('edit.php?page=sort-page&taxonomy='.$term->taxonomy.'&term_id='.$term->term_id).'">' . __('Order', 'cps') . '</a>';
		}
		return $actions;
	}
	
	public function save() {
		if ( isset( $_POST['sort'] ) AND is_array($_POST['sort']) && check_admin_referer( 'save_sort', 'category_custom_post_order' ) ) 
		{
			foreach($_POST['sort'] as $order=>$post_id) 
			{
				$meta_key = 'sort_' . $this->term_id;
				if( isset( $_POST['submit'] )) {
					add_post_meta( $post_id, $meta_key, $order, true ) || update_post_meta( $post_id, $meta_key, $order );
					add_option( 'post_sorter_'.$this->term_id, 1 );
				}
				if( isset( $_POST['remove'] )) {
					delete_post_meta( $post_id, $meta_key );
					delete_option( 'post_sorter_'.$this->term_id );
				}
			}
			$url = 'edit.php?page=sort-page&taxonomy='.$this->taxonomy.'&term_id='.$this->term_id;
			wp_redirect( admin_url( $url ) ); 
			exit();
		}
	}

	public function admin_page() {
		$term = get_term_by('id', $this->term_id, $this->taxonomy );
		$term_link = get_term_link( $term );
		if( !isset( $term->name ) ) return;
		$is_sorted = get_option( 'post_sorter_'.$this->term_id );

		$args = array(
			'tax_query' => array( 'relation' => 'AND', array('taxonomy'=>$term->taxonomy, 'field'=>'term_id', 'terms'=>$term->term_id) ),
			'posts_per_page' => -1
		);
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
							<?php $order = get_post_meta( get_the_ID(), 'sort_' . $term->term_id, true); ?>
							
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
				<p class="submit">
					<input type="submit" name="submit" id="submit" class="button button-primary" value="<?php _e('Reorder', 'cps'); ?>"  />
					<?php if( $is_sorted ): ?>
					<input type="submit" name="remove" id="submit" class="button button-secondary" value="<?php _e('Remove order', 'cps'); ?>"  />
					<?php endif; ?>
				</p>
				</form>
			</div>
		<?php
	}
}