<?php
/**
Plugin Name: Advanced Comments Moderation
Plugin URI: http://www.leavingworkbehind.com
Description: Advanced Comments Moderation makes managing your WordPress blog's comments far easier.
Version: 1.4
Author: Tom Ewer and Tito Pandu
Author URI: http://www.leavingworkbehind.com
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ACM_VERSION' ) ) {
	define( 'ACM_VERSION', '1.5' );
} // end if

/**
 * @version 1.0
 * 
 */
class Advanced_Comments_Moderation
{

	/*--------------------------------------------*
	 * Constructor
	 *--------------------------------------------*/

	/**
	 * Static property to hold our singleton instance
	 *
	 * @since   1.0
	 */
	static $instance = false;

	/**
	 * Instance options
	 *
	 * @since   1.0
	 */
	private $options = array();
	private $comments_dismissed_array;
	private $dismissed_num;

	/**
	 * Initializes the plugin by setting localization, filters, and administration functions.
	 * constructor is private to force the use of get_instance() to make this a Singleton
	 *
	 * @since   1.0
	 */
	private function __construct()
	{
		global $pagenow;

		add_action( 'admin_menu', array( $this, 'edit_admin_menus' ) );
		add_action( 'admin_notices',  array( $this, 'admin_notice' ) );
		add_action( 'admin_init',  array( $this, 'index_comments' ) );
		add_action( 'admin_notices',  array( $this, 'second_admin_notice' ) );
		add_action( 'admin_notices',  array( $this, 'third_admin_notice' ) );
		add_action( 'admin_init',  array( $this, 'setting_comments' ) );
		add_action( 'admin_init',  array( $this, 'lwb_dismiss' ) );
		add_action( 'comment_post', array( $this, 'add_has_replied_meta' ) );
		add_action( 'edit_comment', array( $this, 'add_has_replied_meta' ) );
		add_action( 'delete_comment', array( $this, 'remove_has_replied_meta' ) );
		add_action( 'comment_post', array( $this, 'add_from_author_meta' ) );

		if ( 'edit-comments.php' != $pagenow ) {
			return;
		}

		$this->options = (array)get_option( 'acm_options' );
		$this->options['hide_authors'] = empty( $this->options['hide_authors'] ) ? array() : $this->options['hide_authors'];
		$this->dismissed_num = $this->get_dismissed_count();

		// Load plugin textdomain
		add_action( 'init', array( $this, 'plugin_textdomain' ) );
		add_action( 'current_screen', array( $this, 'comments_lazy_hook' ), 10, 2 );
	}

	/**
	 * Delay hooking our clauses filter to ensure it's only applied when needed.
	 */
	public function comments_lazy_hook( $screen )
	{

		if ( $screen->id != 'edit-comments' )
			return;

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_stylesheets' ) );

		if ( isset( $_GET['advanced_comments_moderation'] ) ) {
			$this->check_comment_has_not_replied();
			$this->check_comment_from_author();

			$this->comments_dismissed = implode( ',', $this->comments_dismissed() );
			$this->comments_has_replied = implode( ',', $this->comments_has_replied() );
			$this->comments_from_author = implode( ',', $this->comments_from_author() );

			if ( $this->options['have_replied'] ) {
				add_filter(
					'comments_clauses',
					array( $this, 'get_comments_missing_replies_and_not_dismissed' ), 10, 2 
				);
				
				add_filter(
					'comment_row_actions',
					array( $this, 'add_dismiss_to_comment_row' ), 10, 2
				);
			}

			if ( $this->options['authors_comments'] ) {
				add_filter(
					'comments_clauses',
					array( $this, 'get_comment_list_except_by_author' ), 10, 2
				);
			}

			if ( $this->options['show_pingbacks'] ) {
				add_filter(
					'comments_clauses', array( $this, 'except_pingback_and_trackback' ), 10, 2
				);
			}

			// add_action( 'pre_get_comments', array( $this, 'return_not_replied_list' ) );
		}

		add_filter( 'comment_status_links', array( $this, 'get_list_dismissed_comments' ) );
		add_filter( 'comment_status_links', array( $this, 'get_list_comments_has_not_replied' ) );

		if ( isset( $_GET['dismissed_comment'] ) ) {
			add_action( 'pre_get_comments', array( $this, 'return_dismissed_list' ) );
			add_filter( 'comment_row_actions', array( $this, 'add_undismiss_to_comment_row' ), 10, 2 );
		}

	} // end comments_lazy_hook


	/**
	 * If an instance exists, this returns it.  If not, it creates one and
	 * retuns it.
	 *
	 * @since   1.0
	 */
	public static function get_instance() 
	{

		if ( !self::$instance ) {
			self::$instance = new self;
		} // end if

		return self::$instance;

	} // end get_instance

	/*--------------------------------------------*
	 * Dependencies
	 *--------------------------------------------*/

	/**
	 * Loads the plugin text domain for translation
	 *
	 * @since   1.0
	 */
	public function plugin_textdomain() 
	{

		// Set filter for plugin's languages directory
		$lang_fir = dirname( plugin_basename( __FILE__ ) ) . '/lang/';
		$lang_fir = apply_filters( 'cnrt_languages_directory', $lang_fir );

		// Traditional WordPress plugin locale filter
		$locale        = apply_filters( 'plugin_locale',  get_locale(), 'cnrt' );
		$mofile        = sprintf( '%1$s-%2$s.mo', 'cnrt', $locale );

		// Setup paths to current locale file
		$mofile_local  = $lang_fir . $mofile;
		$mofile_global = WP_LANG_DIR . '/cnrt/' . $mofile;

		if ( file_exists( $mofile_global ) ) {
			// Look in global /wp-content/languages/cnrt folder
			load_textdomain( 'cnrt', $mofile_global );
		} elseif ( file_exists( $mofile_local ) ) {
			// Look in local /wp-content/plugins/comments-not-replied-to/languages/ folder
			load_textdomain( 'cnrt', $mofile_local );
		} else {
			// Load the default language files
			load_plugin_textdomain( 'cnrt', false, $lang_fir );
		} // end if/else

	} // end plugin_textdomain

	/*--------------------------------------------*
	 * Actions and Filters
	 *--------------------------------------------*/
	/**
	 * Return the dismissed comment in a list on the advanced comments table
	 * @param   int                 $comments       The object array of comments
	 * @return  array               The filtered comment data
	 *
	 * @since   1.0
	 */

	/**
	 * Add the meta tag to comments for query logic later
	 * @param	int		$comment_id		The ID of the comment for which to retrieve replies.
	 * @return	bool					Whether or not the post author has replied.
	 *
	 * @since	1.0
	 */

	public function add_has_replied_meta( $comment_id = 0 ) {

		// get comment object array to run author comparison
		$comm_data		= get_comment( $comment_id );

		// grab post ID and user ID to check
		$comm_parent    = $comm_data->comment_parent;
		$comm_post_id   = $comm_data->comment_post_ID;
		$comm_user_id   = $comm_data->user_id;
		$comm_email 	= $comm_data->comment_author_email;

		// grab post object to compare
		$comm_post_obj	= get_post( $comm_post_id );
		$comm_post_auth	= $comm_post_obj->post_author;

		if ( $comm_parent == 0 )
			return;

		if ( 
			$this->comment_is_by_post_author( $comment_id )
		) {
			update_comment_meta( $comm_parent, '_acm_has_replied', 1 );
		}

	} // end add_has_replied_meta

	public function remove_has_replied_meta( $comment_id = 0 ) {

		// get comment object array to run author comparison
		$comm_data		= get_comment( $comment_id );

		// grab post ID and user ID to check
		$comm_parent    = $comm_data->comment_parent;
		$comm_post_id   = $comm_data->comment_post_ID;
		$comm_user_id   = $comm_data->user_id;
		$comm_email 	= $comm_data->comment_author_email;

		// grab post object to compare
		$comm_post_obj	= get_post( $comm_post_id );
		$comm_post_auth	= $comm_post_obj->post_author;

		if ( $comm_parent == 0 )
			return;

		if ( 
			$this->comment_is_by_post_author( $comment_id )
		) {
			update_comment_meta( $comm_parent, '_acm_has_replied', 0 );
		}

	} // end add_has_replied_meta

	public function add_from_author_meta( $comment_id = 0 ) {

		// get comment object array to run author comparison
		$comm_data		= get_comment( $comment_id );

		// grab post ID and user ID to check
		$comm_post_id   = $comm_data->comment_post_ID;
		$comm_user_id   = $comm_data->user_id;
		$comm_email 	= $comm_data->comment_author_email;

		// grab post object to compare
		$comm_post_obj	= get_post( $comm_post_id );
		$comm_post_auth	= $comm_post_obj->post_author;

		if ( 
			$this->comment_is_by_post_author( $comment_id )
		) {
			update_comment_meta( $comm_post_id, '_acm_is_author', 1 );
		} else {
			update_comment_meta( $comm_post_id, '_acm_is_author', 0 );
		}

	} // end add_from_author_meta

	/* Display a notice that can be dismissed */

	function admin_notice() {
	    global $current_user ;
	    $user_id = $current_user->ID;
	    /* Check that the user hasn't already clicked to ignore the message */
	    if ( ! get_user_meta($user_id, '_acm_index_comments') ) {
	        echo '<div class="updated"><p>'; 
	        printf( __('Advanced Comments Moderation must first index your comments before it can be activated. Please note that this process may take up to 60 seconds.') );
	        echo "</p><p>";
	        printf( __('<a class="button" href="%1$s">Index comments</a>'), admin_url('edit-comments.php?advanced_comments_moderation=1&acm_index_comments=0') );
	        echo "</p></div>";
	    }
	}

	function second_admin_notice() {
	    global $current_user ;
	    $user_id = $current_user->ID;
	    /* Check that the user hasn't already clicked to ignore the message */
	    if ( get_user_meta($user_id, '_acm_index_comments') &&
	    	 ! get_user_meta($user_id, '_acm_setting_comments' ) ) {
	        echo '<div class="updated"><p>'; 
	        printf( __('Advanced Comments Moderation has successfully completed the comments indexing process! Please enable the plugin\'s functionality options via the Discussion Settings screen.') );
	        echo "</p><p>";
	        printf( __('<a class="button" href="%1$s">Go to Discussion Settings</a>'), admin_url('options-discussion.php?acm_setting_comments=0') );
	        printf( __(' or <a class="button" href="%1$s">Go to Advanced Comments tab</a>'), admin_url('edit-comments.php?advanced_comments_moderation=1') );
	        echo "</p></div>";
	    }
	}

	/* Display a notice that can be dismissed */

	function third_admin_notice() {
	    global $current_user ;
	    $user_id = $current_user->ID;
	    /* Check that the user hasn't already clicked to ignore the message */
	    if ( ! get_user_meta($user_id, '_acm_lwb_plugin') ) {
	        echo '<div class="updated"><p>'; 
	        printf( __('Thank you for installing Advanced Comments Moderation! It is the brainchild of Tom Ewer, the founder of') );
	        printf( __(' <a href="%1$s">Leaving Work Behind</a> '), 'http://www.leavingworkbehind.com/?utm_source=plugins&utm_medium=banner&utm_campaign=plugins' );
	        printf( __('-- a community for people who want to build successful online businesses.') );
	        printf( __('<br/><br/><a class="button" href="%1$s">Dismiss</a> '), '?acm_lwb_dismiss=0' );
	        echo "</p></div>";
	    }
	}

	function index_comments() {
	    global $current_user;
	    $user_id = $current_user->ID;
	    /* If user clicks to ignore the notice, add that to their user meta */
	    if ( isset( $_GET['acm_index_comments'] ) && '0' == $_GET['acm_index_comments'] ) {
			add_user_meta( $user_id, '_acm_index_comments', 'true', true );
			$this->check_comment_has_not_replied();
			$this->check_comment_from_author();
			wp_redirect( admin_url( 'options-discussion.php'), '302' );
			exit;
	    }
	}

	function setting_comments()
	{
		global $current_user;
	    $user_id = $current_user->ID;
	    /* If user clicks to ignore the notice, add that to their user meta */
	    if ( isset( $_GET['acm_setting_comments'] ) && '0' == $_GET['acm_setting_comments'] ) {
			add_user_meta( $user_id, '_acm_setting_comments', 'true', true );
			wp_redirect( admin_url( 'options-discussion.php#acm_settings'), '302' );
			exit;
	    }
	}

	function lwb_dismiss() {
	    global $current_user;
	    $user_id = $current_user->ID;
	    /* If user clicks to ignore the notice, add that to their user meta */
	    if ( isset($_GET['acm_lwb_dismiss']) && '0' == $_GET['acm_lwb_dismiss'] ) {
	        add_user_meta($user_id, '_acm_lwb_plugin', 'true', true);
	    } else if ( isset($_GET['acm_lwb_dismiss']) && '1' == $_GET['acm_lwb_dismiss'] ) {
	        delete_user_meta($user_id, '_acm_lwb_plugin');
	    }
	}
	
	function edit_admin_menus() {  
		global $menu;

		$menu[25][2] = $menu[25][2] . '?advanced_comments_moderation=1';
	}

	public function return_dismissed_list( $comments = array() ) 
	{

		// bail on anything not admin
		if ( ! is_admin() )
			return;

		// only run this on the comments table
		$current_screen = get_current_screen();

		if ( 'edit-comments' !== $current_screen->base )
			return;

		// check for query param
		if ( ! isset( $_GET['dismissed_comment'] ) )
			return;

		// now run action to show missing
		$comments->query_vars['meta_key']   = '_acm_dismiss';
		$comments->query_vars['meta_value'] = '1';

		// Because at this point, the meta query has already been parsed,
		// we need to re-parse it to incorporate our changes
		$comments->meta_query->parse_query_vars( $comments->query_vars );

	} // end return_dismissed_list

	public function return_not_dismissed_list( $comments = array() ) 
	{

		// bail on anything not admin
		if ( ! is_admin() )
			return;

		// only run this on the comments table
		$current_screen = get_current_screen();

		if ( 'edit-comments' !== $current_screen->base )
			return;

		// check for query param
		if ( ! isset( $_GET['advanced_comments_moderation'] ) )
			return;

		// now run action to show missing
		$comments->query_vars['meta_key']   = '_acm_dismiss';
		$comments->query_vars['meta_value'] = false;

		// Because at this point, the meta query has already been parsed,
		// we need to re-parse it to incorporate our changes
		$comments->meta_query->parse_query_vars( $comments->query_vars );

	} // end return_dismissed_list

	public function get_comments_missing_replies_and_not_dismissed( $clauses )
	{
		$comments_dismissed_array = $this->comments_dismissed;
		$comments_has_replied_array = $this->comments_has_replied;

		global $wpdb;

		$clauses['where'] .= $wpdb->prepare( ' AND (' . $wpdb->prefix . 'comments.comment_ID NOT IN ( ' . $comments_dismissed_array . ' ))', null );
		$clauses['where'] .= $wpdb->prepare( ' AND (' . $wpdb->prefix . 'comments.comment_ID NOT IN ( ' . $comments_has_replied_array . ' ))', null );
		$clauses['where'] .= $wpdb->prepare( ' AND (' . $wpdb->prefix . 'comments.comment_type = %s)', '' );

		return $clauses;
	}

	/**
	 * Show except the Comments MADE BY the current logged user
	 * and the Comments MADE TO his/hers posts.
	 * Runs except for the Author role.
	 */
	public function get_comment_list_except_by_author( $clauses ) 
	{
		$comments_from_author_array = $this->comments_from_author;

		global $wpdb;

		$clauses['where'] .= $wpdb->prepare( ' AND (' . $wpdb->prefix . 'comments.comment_ID NOT IN ( ' . $comments_from_author_array . ' ))', null );

		return $clauses;
	} // end get_comment_list_by_user_post

	public function get_list_comments_has_not_replied( $status_links )
	{
		if ( isset( $_GET['advanced_comments_moderation'] ) ) {
			$status_links['all'] = '<a href="edit-comments.php?comment_status=all">All</a>';
			$status_links = array('advanced_comments_moderation' => '<a href="edit-comments.php?advanced_comments_moderation=1" class="current">Advanced</a>') + $status_links;
		} else {
			$status_links = array('advanced_comments_moderation' => '<a href="edit-comments.php?advanced_comments_moderation=1">Advanced</a>') + $status_links;
		}

		return $status_links;
	}

	public function get_list_dismissed_comments( $status_links )
	{
		$current = isset( $_GET['dismissed_comment'] ) ? 'class="current"' : '';

		// get missing count
		$dismiss_num    = $this->dismissed_num;

		// create link
		$status_link    = '<a href="edit-comments.php?dismissed_comment=1" ' . $current . '>';
		$status_link    .= __( 'Dismissed', 'cnrt' );
		$status_link    .= ' <span class="count">(<span class="pending-count">' . $dismiss_num . '</span>)</span>';
		$status_link    .= '</a>';
		
		if ( isset( $_GET['dismissed_comment'] ) ) {
			$status_links['all'] = '<a href="edit-comments.php?comment_status=all">All</a>';
		}
		
		$status_links = array('dismissed_comment' => $status_link) + $status_links;

		return $status_links;
	}

	/**
	 * Show except the pingbacks and trackbacks
	 */
	public function except_pingback_and_trackback( $clauses ) 
	{
		global $wpdb;

		$clauses['where'] .= $wpdb->prepare( ' AND (' . $wpdb->prefix . 'comments.comment_type != %s AND ' . $wpdb->prefix . 'comments.comment_type != %s ) ', 'trackback', 'pingback' );

		return $clauses;
	} // end pingback_and_trackback

	public function add_dismiss_to_comment_row( $actions, $comment )
	{
		$nonce = wp_create_nonce( 'dismiss_comment_nonce' );
		$link = admin_url( 'admin-ajax.php?action=dismiss_comment&comment_id=' . $comment->comment_ID . '&nonce=' . $nonce );
		
		$actions['dismiss'] = '<a class="dismiss-comment" data-nonce="' . $nonce . '" data-comment_id="' . $comment->comment_ID . '" href="' . $link . '" title="' . esc_attr__( 'Dismiss Comment' ) . '">Dismiss</a>';

		return $actions;
	}

	public function add_undismiss_to_comment_row( $actions, $comment )
	{
		$nonce = wp_create_nonce( 'undismiss_comment_nonce' );
		$link = admin_url( 'admin-ajax.php?action=undismiss_comment&comment_id=' . $comment->comment_ID . '&nonce=' . $nonce );
		
		$actions['undismiss'] = '<a class="undismiss-comment" data-nonce="' . $nonce . '" data-comment_id="' . $comment->comment_ID . '" href="' . $link . '" title="' . esc_attr__( 'Un-Dismiss Comment' ) . '">Un-Dismiss</a>';

		return $actions;
	}

	/**
	 * Add CSS to the admin head
	 *
	 * @return  void
	 *
	 * @since   1.0
	 */

	public function admin_css() {

		$current_screen = get_current_screen();

		if ( $current_screen->base !== 'edit-comments' )
			return;

		echo '<style type="text/css">
			#the-comment-list .dismiss a {
				color: #d98500;
			}
			#the-comment-list .undismiss a {
				color: #006505;
			}
			</style>';

	} // end admin_css

	/*--------------------------------------------*
	 * Helper Functions
	 *--------------------------------------------*/

	/**
	 * Initializes the plugin by setting localization, filters, and administration functions.
	 *
	 * @param   int     $comment_id     The ID of the comment for the given post.
	 * @return  bool                    Whether or not the comment is also by the the post author
	 * @since   1.0
	 */
	private function comment_is_by_post_author( $comment_id = 0 ) 
	{

		$comment = get_comment( $comment_id );
		$post    = get_post( $comment->comment_post_ID );

		return $comment->comment_author_email == $this->get_post_author_email( $post->ID ) || 
				in_array( $comment->comment_author_email, $this->options['hide_authors'] );

	} // end if

	/**
	 * Retrieves all of the replies for the given comment.
	 *
	 * @param   int     $comment_id     The ID of the comment for which to retrieve replies.
	 * @return  array                   The array of replies
	 * @since   1.0
	 */
	private function get_comment_replies( $comment_id = 0 ) 
	{

		global $wpdb;
		$replies = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_ID, comment_author_email, comment_post_ID FROM $wpdb->comments WHERE comment_parent = %d",
				$comment_id
			)
		);

		return $replies;

	} // end get_comment_replies

	/**
	 * Determines whether or not the author has replied to the comment.
	 *
	 * @param   array   $replies        The array of replies for a given comment.
	 * @return  bool                    Whether or not the post author has replied.
	 * @since   1.0
	 */
	private function author_has_replied( $replies = array() ) 
	{

		$author_has_replied = false;

		// If there are no replies, the author clearly hasn't replied
		if ( 0 < count( $replies ) ) {
			$comment_count = 0;
			while ( $comment_count < count( $replies ) && ! $author_has_replied ) {
				// Read the current comment
				$current_comment = $replies[ $comment_count ];

				// If the comment author email address is the same as the post author's address, then we've found a reply by the author.
				if ( $current_comment->comment_author_email == $this->get_post_author_email( $current_comment->comment_post_ID ) ) {
					$author_has_replied = true;
					break;
				} // end if

				// Now on to the next comment
				$comment_count++;
			} // end while
		} // end if/else

		return $author_has_replied;

	} // end author_has_replied

	private function check_comment_has_not_replied()
	{
		$commentsQuery = new WP_Comment_Query;

		// add_filter(
		// 	'comments_clauses',
		// 	array( $this, 'get_comments_missing_replies_and_not_dismissed' ), 10, 2
		// );

		add_filter(
			'comments_clauses', array( $this, 'except_pingback_and_trackback' ), 10, 2
		);

		$args = array(
			'meta_query' => array(
				array(
					'key' => '_acm_has_replied',
					'compare' => 'NOT EXISTS',
				)
			)
		);

		$comments = $commentsQuery->query( $args );
		$comment_has_not_replied = array();

		foreach ( $comments as $key => $comment ) {
			// First, we get all of the replies for this comment
			$replies = $this->get_comment_replies( $comment->comment_ID );
			
			// Note whether or not the comment author has replied.
			if ( ! $this->author_has_replied( $replies ) ) {
				$comment_has_not_replied[] = $comment->comment_ID;
				$has_not_replied = update_comment_meta( $comment->comment_ID, '_acm_has_replied', 0 );
			} else {
				$has_replied = update_comment_meta( $comment->comment_ID, '_acm_has_replied', 1 );
			}
		}
		
		return $comment_has_not_replied;
	}

	private function check_comment_from_author()
	{
		$commentsQuery = new WP_Comment_Query;

		// add_filter(
		// 	'comments_clauses',
		// 	array( $this, 'get_comments_missing_replies_and_not_dismissed' ), 10, 2
		// );

		add_filter(
			'comments_clauses', array( $this, 'except_pingback_and_trackback' ), 10, 2
		);

		$args = array(
			'meta_query' => array(
				array(
					'key' => '_acm_is_author',
					'compare' => 'NOT EXISTS',
				)
			)
		);

		$comments = $commentsQuery->query( $args );
		$comment_from_author = array();

		foreach ( $comments as $key => $comment ) {
			
			// Note whether or not the comment from author.
			if ( $this->comment_is_by_post_author( $comment ) ) {
				$comment_from_author[] = $comment->comment_ID;
				$is_author = update_comment_meta( $comment->comment_ID, '_acm_is_author', 1 );
			} else {
				$is_not_author = update_comment_meta( $comment->comment_ID, '_acm_is_author', 0 );
			}
		}
		
		return $comment_from_author;
	}

	/**
	 * Retrieves the email address for the author of the post.
	 *
	 * @param   int     $post_id        The ID of the post for which to retrieve the email address
	 * @return  string                  The email address of the post author
	 * @since   1.0
	 */
	private function get_post_author_email( $post_id = 0 ) 
	{

		// Get the author information for the specified post
		$post   = get_post( $post_id );
		$author = get_user_by( 'id', $post->post_author );

		// Let's store the author data as the author
		$author = $author->data;

		return $author->user_email;

	} // end get_post_author_email

	/**
	 * Return number of comments with missing replies, either global or per post
	 * @param   int     $post_id        optional post ID for which to retrieve count.
	 * @return  int                     the count
	 *
	 * @since   1.0
	 */

	public function get_dismissed_count() 
	{

		$args = array(
			'meta_key'   => '_acm_dismiss',
			'meta_value' => '1',
		);

		$comments = get_comments( $args );

		$count    = ! empty( $comments ) ? count( $comments ) : '0';

		return $count;

	} // end get_dismissed_count

	private function comments_dismissed()
	{
		$commentsQuery = new WP_Comment_Query;
		$comments = $commentsQuery->query( array(
		    'meta_key' => '_acm_dismiss',
		    'meta_value' => '1'
		) );

		$comments_dismissed = array_map( array( $this, 'return_comment_id' ), $comments );

		if ( empty( $comments_dismissed ) ) {
			$comments_dismissed = array( 0 );
		}

		return $comments_dismissed;
	}

	private function return_comment_id($value)
	{
		return $value->comment_ID;
	}

	private function comments_has_replied()
	{
		$commentsQuery = new WP_Comment_Query;
		$comments = $commentsQuery->query( array(
		    'meta_key' => '_acm_has_replied',
		    'meta_value' => '1'
		) );

		$comments_has_replied = array_map( array( $this, 'return_comment_id' ), $comments );

		if ( empty( $comments_has_replied ) ) {
			$comments_has_replied = array( 0 );
		}

		return $comments_has_replied;
	}

	private function comments_from_author()
	{
		$commentsQuery = new WP_Comment_Query;
		$comments = $commentsQuery->query( array(
		    'meta_key' => '_acm_is_author',
		    'meta_value' => '1'
		) );

		$comments_from_author = array_map( array( $this, 'return_comment_id' ), $comments );

		if ( empty( $comments_from_author ) ) {
			$comments_from_author = array( 0 );
		}

		return $comments_from_author;
	}

	/*-----------------------------------------------*
	 * Enqueue Scripts & Styles
	 *-----------------------------------------------*/

	public function admin_stylesheets() 
	{
		wp_register_style( 'acm_admin_css', plugins_url( 'css/styles.css', __FILE__ ) );
		wp_enqueue_style( 'acm_admin_css' );
	}

	public function admin_scripts()
	{
		wp_enqueue_script( 'acm_custom_script', plugins_url( 'js/application.js', __FILE__ ), array( 'jquery' ) );
	}
}

/**
 * Instantiates the plugin using the plugins_loaded hook and the
 * Singleton Pattern.
 */
function load_advanced_comments_moderation() {
	Advanced_Comments_Moderation::get_instance();
} // end Advanced_Comments_Moderation
add_action( 'plugins_loaded', 'load_advanced_comments_moderation' );

// Hook for admin-ajax.php
add_action( 'wp_ajax_dismiss_comment', 'acm_dismiss_comment', 10, 2 );
add_action( 'wp_ajax_undismiss_comment', 'acm_undismiss_comment', 10, 2 );

/**
 * Callback function for dismiss comment action in admin-ajax.php
 *
 * @return void
 * @author Tito Pandu Brahmanto
 **/
function acm_dismiss_comment() {

	if ( !wp_verify_nonce( $_REQUEST['nonce'], 'dismiss_comment_nonce' ) ) {
		exit( 'No naughty business please' );
	}

	$comment_id = $_REQUEST['comment_id'];

	$dismiss = add_comment_meta( $comment_id, '_acm_dismiss', true, true );

	if ( false === $dismiss ) {
		$result = array( 'type' => 'error' );
	} else {
		$result = array( 'type' => 'success', 'comment_id' => $comment_id );
	}

	if ( ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) 
		&& strtolower( $_SERVER['HTTP_X_REQUESTED_WITH'] ) == 'xmlhttprequest' ) {
		$result = json_encode( $result );
		echo $result;
	} else {
		header( 'Location: ' . $_SERVER['HTTP_REFERER'] );
	}

	die();
}

/**
 * Callback function for dismiss comment action in admin-ajax.php
 *
 * @return void
 * @author Tito Pandu Brahmanto
 **/
function acm_undismiss_comment() {

	if ( ! wp_verify_nonce( $_REQUEST['nonce'], 'undismiss_comment_nonce' ) ) {
		exit( 'No naughty business please' );
	}

	$comment_id = $_REQUEST['comment_id'];

	$dismiss = delete_comment_meta( $comment_id, '_acm_dismiss' );

	if ( false === $dismiss ) {
		$result = array( 'type' => 'error' );
	} else {
		$result = array( 'type' => 'success', 'comment_id' => $comment_id );
	}

	if ( ! empty( $_SERVER['HTTP_X_REQUESTED_WITH'] ) 
		&& strtolower( $_SERVER['HTTP_X_REQUESTED_WITH'] ) == 'xmlhttprequest' ) {
		$result = json_encode( $result );
		echo $result;
	} else {
		header( 'Location: ' . $_SERVER['HTTP_REFERER'] );
	}

	die();
}

/**
 * Registers a new settings field on the 'Discussion Settings' page of the WordPress dashboard.
 */
function acm_initialize_theme_options() {

	// Let's introduce a section to be rendered on the new options page
	add_settings_section(
		'acm_section',                          // The ID to use for this section in attribute tags
		'Advanced Comments Moderation Options', // The title of the section rendered to the screen
		'acm_options_display',                  // The function used to render the options for this section
		'discussion'                            // The ID of the page on which this section is rendered
	);

	add_settings_field(
		'acm_have_replied',                  	// The ID (or the name) of the field
		'Hide comments that have been replied to', // The text used to label the field
		'acm_have_replied_display',          	// The callback function used to render the field
		'discussion',                           // The page on which we'll be rendering this field
		'acm_section'                           // The section to which we're adding the setting
	);

	add_settings_field(
		'acm_authors_comments',                 // The ID (or the name) of the field
		'Hide author comments',    				// The text used to label the field
		'acm_authors_comments_display',         // The callback function used to render the field
		'discussion',                           // The page on which we'll be rendering this field
		'acm_section'                           // The section to which we're adding the setting
	);

	add_settings_field(
		'acm_pingbacks',						// The ID (or the name) of the field
		'Hide pingbacks',    					// The text used to label the field
		'acm_pingbacks_display',				// The callback function used to render the field
		'discussion',                           // The page on which we'll be rendering this field
		'acm_section'                           // The section to which we're adding the setting
	);

	add_settings_field(
		'acm_hide_authors',						// The ID (or the name) of the field
		'Hide specified authors',    			// The text used to label the field
		'acm_hide_authors_display',				// The callback function used to render the field
		'discussion',                           // The page on which we'll be rendering this field
		'acm_section'                           // The section to which we're adding the setting
	);
	
	// Register the 'acm_spam_with_avatar' setting with the 'Discussion' section
	register_setting(
		'acm_section',                          // The name of the group of settings
		'acm_options'                           // The name of the actual option (or setting)
	);
	
} // end acm_initialize_theme_options
add_action( 'admin_init', 'acm_initialize_theme_options' );

/*-----------------------------------------------*
 * Callbacks
/*-----------------------------------------------*

/**
 * Renders the description of the setting below the title of the section
 * and the above the actual settings.
 */
function acm_options_display() {
	?>
	<?php settings_fields( 'acm_section' ); ?>
	<span id="acm_settings">
		These options help you to control what's displayed on your <a href="<?php admin_url() ?>edit-comments.php?advanced_comments_moderation=1">Comments Moderation page</a>
	</span>	
	<?php
} // end acm_options_display

function acm_authors_comments_display() {
	// Read the options for the authors_comments section 
	$options = (array)get_option( 'acm_options' );
	$authors_comments = $options['authors_comments'];
	?>
	<label for="acm_options[authors_comments]">
		<input type="checkbox" name="acm_options[authors_comments]" id="acm_options[authors_comments]" value="1" <?php _e( checked( 1, $authors_comments, false ) ); ?> />
	</label>
	<?php
} // end acm_authors_comments_display

function acm_have_replied_display() {
	// Read the options for the have_replied section 
	$options = (array)get_option( 'acm_options' );
	$have_replied = $options['have_replied'];
	?>
	<label for="acm_options[have_replied]">
		<input type="checkbox" name="acm_options[have_replied]" id="acm_options[have_replied]" value="1" <?php _e( checked( 1, $have_replied, false ) ); ?> />
	</label>
	<?php
} // end acm_have_replied_display

function acm_pingbacks_display() {
	// Read the options for the show_pingbacks section 
	$options = (array)get_option( 'acm_options' );
	$show_pingbacks = $options['show_pingbacks'];
	?>
	<label for="acm_options[show_pingbacks]">
		<input type="checkbox" name="acm_options[show_pingbacks]" id="acm_options[show_pingbacks]" value="1" <?php _e( checked( 1, $show_pingbacks, false ) ); ?> />
	</label>
	<?php
} // end acm_pingbacks_display

function acm_hide_authors_display() {
	// Read the options for the hide_authors section 
	$options = (array)get_option( 'acm_options' );
	$hide_authors = empty($options['hide_authors']) ? array() : $options['hide_authors'];
	$authors = new WP_User_Query( array( 'who' => 'authors' ) );

	if ( ! empty( $authors ) ) { ?>
		<ul class="author-list" style="padding: 0; margin-top: 0;">
	<?php 
		// echo var_dump($hide_authors);
		foreach ($authors->results as $author) {
			$is_checked = '';
			if ( in_array( $author->user_email, $hide_authors ) ) {
	            $is_checked = 'checked="checked"';
	        }
		?>
			<li>
				<label for="acm_options[hide_authors][<?php echo $author->user_email ?>]">
					<input type="checkbox" name="acm_options[hide_authors][]" id="acm_options[hide_authors][<?php echo $author->user_email ?>]" value="<?php echo $author->user_email; ?>" <?php echo $is_checked; ?> />
					<?php echo $author->user_login . " (" . $author->user_nicename . ")"; ?>
				</label>
			</li>
		<?php
		}
	?>
		</ul>
	<?php 
	}
} // end acm_hide_authors_display

/**
 * Instantiates the plugin using the plugins_loaded hook and the
 * Singleton Pattern.
 */
function acm_set_option_action() {
	add_option( 'acm_options', array( 'hide_authors' => array() ) );
} // end ACM_Custom_Bulk_Action
add_action( 'plugins_loaded', 'acm_set_option_action' );
 
class ACM_Custom_Bulk_Action {
	/*--------------------------------------------*
	 * Constructor
	 *--------------------------------------------*/

	/**
	 * Static property to hold our singleton instance
	 *
	 * @since   1.0
	 */
	static $instance = false;

	public function __construct() {

		if ( is_admin() ) {
			// admin actions/filters
			add_action( 'admin_footer-edit-comments.php', array(&$this, 'custom_bulk_admin_footer') );
			add_action( 'load-edit-comments.php', array(&$this, 'custom_bulk_action') );
			add_action( 'admin_notices', array(&$this, 'custom_bulk_admin_notices') );
		}
	}
	
	/**
	 * If an instance exists, this returns it.  If not, it creates one and
	 * retuns it.
	 *
	 * @since   1.0
	 */
	public static function get_instance() 
	{

		if ( !self::$instance ) {
			self::$instance = new self;
		} // end if

		return self::$instance;

	} // end get_instance
	
	/**
	 * Step 1: add the custom Bulk Action to the select menus
	 */
	function custom_bulk_admin_footer() {
		
		?>
			<script type="text/javascript">
				jQuery(document).ready(function($) {
					$('#comments-form').on('submit', function (event) {
						event.preventDefault();
						var commentCount = $("tr.comment input[name='delete_comments[]']:checked:enabled").length;
						var confirmApplyAction = true;
						if ($("select[name='action']").val() === 'dismiss' || $("select[name='action']").val() === 'undismiss') {
							if (commentCount > 100) {
								confirmApplyAction = confirm("This action may fail if you select more than 100 comments. Do you want to continue?");
							};
						};
						event.stopPropagation();
						if (confirmApplyAction === true) {
							$(this).unbind('submit').submit();
						};
					});
		<?php 
		if ( isset( $_GET['advanced_comments_moderation'] ) ) {
			?>
					$("tr.comment input[name='delete_comments[]']").on('click', function (e) {
						console.log($("tr.comment input[name='delete_comments[]']:checked:enabled").length);
					});

					$('<option>').val('dismiss').text('<?php _e( 'Dismiss' ) ?>').appendTo("select[name='action']");
			<?php
		} 
		if ( isset( $_GET['dismissed_comment'] ) ) {
			?>
					$('<option>').val('undismiss').text('<?php _e( 'Undismiss' ) ?>').appendTo("select[name='action']");

		<?php
		};
		?>
					
				});
			</script>
		<?php
	}
	
	
	/**
	 * Step 2: handle the custom Bulk Action
	 * 
	 * Based on the post http://wordpress.stackexchange.com/questions/29822/custom-bulk-action
	 */
	function custom_bulk_action() {
		// get the action
		$wp_list_table = _get_list_table( 'WP_Posts_List_Table' );  // depending on your resource type this could be WP_Users_List_Table, WP_Comments_List_Table, etc
		$action = $wp_list_table->current_action();
		
		$allowed_actions = array( 'dismiss', 'undismiss' );
		if ( ! in_array( $action, $allowed_actions ) ) return;
		
		// security check
		check_admin_referer( 'bulk-comments' );
		
		// make sure ids are submitted.  depending on the resource type, this may be 'media' or 'ids'
		if ( isset( $_REQUEST['delete_comments'] ) )
			$delete_comments_ids = array_map( 'intval', $_REQUEST['delete_comments'] );
		
		if ( empty( $delete_comments_ids ) ) return;
		
		// this is based on wp-admin/edit.php
		$sendback = remove_query_arg( array('dismissed', 'undismissed', 'untrashed', 'deleted', 'ids'), wp_get_referer() );
		if ( ! $sendback )
			$sendback = admin_url( 'edit-comments.php' );
		
		$pagenum = $wp_list_table->get_pagenum();
		$sendback = add_query_arg( 'paged', $pagenum, $sendback );
		
		switch ( $action ) {
			case 'dismiss':
				// if we set up user permissions/capabilities, the code might look like:
				//if ( !current_user_can($post_type_object->cap->export_post, $post_id) )
				//  wp_die( __('You are not allowed to export this post.') );
				$dismissed = 0;
				foreach ( $delete_comments_ids as $delete_comment ) {
					if ( ! $this->perform_dismiss( $delete_comment ) )
						wp_die( __( 'Error dismissing post.' ) );
	
					$dismissed++;
				}
				
				$sendback = add_query_arg( array('dismissed' => $dismissed, 'ids' => join( ',', $delete_comments_ids ) ), $sendback );
			break;

			case 'undismiss':
				// if we set up user permissions/capabilities, the code might look like:
				//if ( !current_user_can($post_type_object->cap->export_post, $post_id) )
				//  wp_die( __('You are not allowed to export this post.') );
				$undismissed = 0;
				foreach ( $delete_comments_ids as $delete_comment ) {
					if ( !$this->perform_undismiss( $delete_comment ) )
						wp_die( __( 'Error exporting post.' ) );
	
					$undismissed++;
				}
				
				$sendback = add_query_arg( array('undismissed' => $undismissed, 'ids' => join( ',', $delete_comments_ids ) ), $sendback );
			break;
			
			default: return;
		}
		
		$sendback = remove_query_arg( array('action', 'action2', 'tags_input', 'post_author', 'comment_status', 'ping_status', '_status', 'post', 'bulk_edit', 'post_view'), $sendback );
		
		wp_redirect( $sendback );
		exit();
	}
	
	
	/**
	 * Step 3: display an admin notice on the Posts page after exporting
	 */
	function custom_bulk_admin_notices() {
		global $post_type, $pagenow;
		
		if ( $pagenow == 'edit-comments.php' && 
			isset( $_REQUEST['dismissed'] ) && 
			(int) $_REQUEST['dismissed'] ) {
			$message = sprintf(
				_n(
					'Comment dismissed.',
					'%s posts dismissed.', 
					$_REQUEST['dismissed'] 
				), 
				number_format_i18n( $_REQUEST['dismissed'] ) 
			);
			?>
			<div class='updated'>
				<p><?php esc_html_e( $message ); ?></p>
			</div>
		<?php
		}
	}
	
	function perform_dismiss( $comment_id ) {
		$dismiss = add_comment_meta( $comment_id, '_acm_dismiss', true, true );
		return $dismiss;
	}

	function perform_undismiss( $comment_id ) {
		$undismiss = delete_comment_meta( $comment_id, '_acm_dismiss' );
		return $undismiss;
	}
}

/**
 * Instantiates the plugin using the plugins_loaded hook and the
 * Singleton Pattern.
 */
function acm_load_custom_bulk_action() {
	ACM_Custom_Bulk_Action::get_instance();
} // end ACM_Custom_Bulk_Action
add_action( 'plugins_loaded', 'acm_load_custom_bulk_action' );
