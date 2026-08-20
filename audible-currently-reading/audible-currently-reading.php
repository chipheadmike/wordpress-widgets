<?php
/**
 * Plugin Name: Audible Currently Reading Widget
 * Description: A sidebar widget that shows the audiobook you're currently listening to — cover, title, author, and series — looked up from a typed book title, plus an optional link to Audible.
 * Version: 1.1.0
 * Author: Mike Williams
 * License: GPL-2.0-or-later
 * Text Domain: audible-currently-reading
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Look up title, author, and cover art for a book from Apple's public
 * iTunes Search API. Audible's own product pages block a large share of
 * WordPress hosting IPs outright (bot-protection by IP reputation), so
 * metadata is sourced from Apple's catalog instead — the Audible link is
 * kept as a separate, manually-entered field purely for the "listen on
 * Audible" click-through.
 *
 * @param string $title  Book title to search for.
 * @param string $author Optional author, narrows the search.
 * @return array|WP_Error
 */
function audible_crw_lookup_book_data( $title, $author ) {
	$term = trim( $title . ' ' . $author );
	if ( '' === $term ) {
		return new WP_Error( 'acrw_no_title', __( 'Enter a book title to search for.', 'audible-currently-reading' ) );
	}

	$data = audible_crw_itunes_search( $term, 'audiobook', 'audiobook' );

	if ( is_wp_error( $data ) ) {
		return $data;
	}

	if ( empty( $data ) ) {
		// Not every title is on Apple's audiobook shelf; fall back to their
		// (much larger) regular book catalog so cover/author can still fill in.
		$data = audible_crw_itunes_search( $term, 'ebook', 'ebook' );
	}

	if ( empty( $data ) ) {
		return new WP_Error( 'acrw_no_data', __( 'No match found for that title. Try adding the author, double-check the spelling, or fill in the fields manually below.', 'audible-currently-reading' ) );
	}

	return $data;
}

/**
 * Run one iTunes Search API query and normalize the result.
 *
 * @param string $term   Search term.
 * @param string $media  iTunes "media" param.
 * @param string $entity iTunes "entity" param.
 * @return array|WP_Error Empty array on a clean "no results"; WP_Error on a request failure.
 */
function audible_crw_itunes_search( $term, $media, $entity ) {
	$url = add_query_arg(
		array(
			'term'   => $term,
			'media'  => $media,
			'entity' => $entity,
			'limit'  => 1,
			'country' => 'US',
		),
		'https://itunes.apple.com/search'
	);

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 10,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return new WP_Error(
			'acrw_http_error',
			sprintf(
				/* translators: %d: HTTP status code */
				__( 'The book search API returned an unexpected response (HTTP %d). Try again in a moment.', 'audible-currently-reading' ),
				$code
			)
		);
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body['results'][0] ) ) {
		return array();
	}

	$result = $body['results'][0];

	// Audiobook results use "collectionName"; ebook results use "trackName".
	$raw_title = ! empty( $result['collectionName'] ) ? $result['collectionName'] : ( $result['trackName'] ?? '' );
	// Strip common edition suffixes that aren't part of the title.
	$clean_title = preg_replace( '/\s*\((?:Unabridged|Abridged)\)\s*$/i', '', $raw_title );

	$cover = ! empty( $result['artworkUrl100'] ) ? $result['artworkUrl100'] : '';
	// Request a larger image than the default 100x100 thumbnail.
	$cover = preg_replace( '/\d+x\d+bb(\.(?:jpg|png))$/i', '600x600bb$1', $cover );

	return array(
		'title'     => sanitize_text_field( $clean_title ),
		'author'    => sanitize_text_field( $result['artistName'] ?? '' ),
		'cover_url' => esc_url_raw( $cover ),
	);
}

class Audible_Currently_Reading_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'audible_currently_reading',
			__( 'Currently Reading (Audible)', 'audible-currently-reading' ),
			array(
				'description'                 => __( 'Shows the audiobook you are currently listening to, looked up from a book title.', 'audible-currently-reading' ),
				'customize_selective_refresh' => true,
			)
		);
	}

	public function widget( $args, $instance ) {
		$title      = ! empty( $instance['title'] ) ? $instance['title'] : '';
		$label      = ! empty( $instance['label'] ) ? $instance['label'] : __( 'Currently Reading', 'audible-currently-reading' );
		$link_url   = ! empty( $instance['link_url'] ) ? $instance['link_url'] : '';
		$book_title = ! empty( $instance['book_title'] ) ? $instance['book_title'] : '';
		$author     = ! empty( $instance['author'] ) ? $instance['author'] : '';
		$series     = ! empty( $instance['series'] ) ? $instance['series'] : '';
		$cover_url  = ! empty( $instance['cover_url'] ) ? $instance['cover_url'] : '';

		if ( empty( $book_title ) && empty( $cover_url ) ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput

		if ( ! empty( $title ) ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		echo '<div class="acrw-card">';

		if ( ! empty( $label ) ) {
			echo '<div class="acrw-label">' . esc_html( $label ) . '</div>';
		}

		if ( ! empty( $cover_url ) ) {
			if ( ! empty( $link_url ) ) {
				echo '<a class="acrw-cover-link" href="' . esc_url( $link_url ) . '" target="_blank" rel="noopener noreferrer">';
			}
			echo '<img class="acrw-cover" src="' . esc_url( $cover_url ) . '" alt="' . esc_attr( $book_title ) . '" loading="lazy" />';
			if ( ! empty( $link_url ) ) {
				echo '</a>';
			}
		}

		if ( ! empty( $book_title ) ) {
			echo '<div class="acrw-title">';
			if ( ! empty( $link_url ) ) {
				echo '<a href="' . esc_url( $link_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $book_title ) . '</a>';
			} else {
				echo esc_html( $book_title );
			}
			echo '</div>';
		}

		if ( ! empty( $series ) ) {
			echo '<div class="acrw-series"><strong>' . esc_html__( 'Series:', 'audible-currently-reading' ) . '</strong> ' . esc_html( $series ) . '</div>';
		}

		if ( ! empty( $author ) ) {
			echo '<div class="acrw-author"><strong>' . esc_html__( 'Author:', 'audible-currently-reading' ) . '</strong> ' . esc_html( $author ) . '</div>';
		}

		echo '</div>'; // .acrw-card

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function form( $instance ) {
		$title         = ! empty( $instance['title'] ) ? $instance['title'] : '';
		$label         = ! empty( $instance['label'] ) ? $instance['label'] : __( 'Currently Reading', 'audible-currently-reading' );
		$search_title  = ! empty( $instance['search_title'] ) ? $instance['search_title'] : '';
		$search_author = ! empty( $instance['search_author'] ) ? $instance['search_author'] : '';
		$link_url      = ! empty( $instance['link_url'] ) ? $instance['link_url'] : '';
		$book_title    = ! empty( $instance['book_title'] ) ? $instance['book_title'] : '';
		$author        = ! empty( $instance['author'] ) ? $instance['author'] : '';
		$series        = ! empty( $instance['series'] ) ? $instance['series'] : '';
		$cover_url     = ! empty( $instance['cover_url'] ) ? $instance['cover_url'] : '';
		$fetch_error   = ! empty( $instance['fetch_error'] ) ? $instance['fetch_error'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Widget Title:', 'audible-currently-reading' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'label' ) ); ?>"><?php esc_html_e( 'Heading:', 'audible-currently-reading' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'label' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'label' ) ); ?>" type="text" value="<?php echo esc_attr( $label ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'search_title' ) ); ?>"><?php esc_html_e( 'Book Title to search for:', 'audible-currently-reading' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'search_title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'search_title' ) ); ?>" type="text" value="<?php echo esc_attr( $search_title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'search_author' ) ); ?>"><?php esc_html_e( 'Author (optional, narrows the search):', 'audible-currently-reading' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'search_author' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'search_author' ) ); ?>" type="text" value="<?php echo esc_attr( $search_author ); ?>">
			<br><small><?php esc_html_e( 'Cover, title, and author are looked up automatically when you save. This is a text search, not an exact catalog lookup — use the plain title as printed on the cover (skip series/subtitle wording) and add the author to disambiguate, then double-check the result below. Series is not available from this lookup — fill it in below by hand if you want it shown.', 'audible-currently-reading' ); ?></small>
		</p>
		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'refetch' ) ); ?>" value="1">
				<?php esc_html_e( 'Re-run the search on save', 'audible-currently-reading' ); ?>
			</label>
		</p>
		<?php if ( $fetch_error ) : ?>
			<p style="color:#b32d2e;"><?php echo esc_html( $fetch_error ); ?></p>
		<?php endif; ?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'link_url' ) ); ?>"><?php esc_html_e( 'Link URL (optional):', 'audible-currently-reading' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'link_url' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'link_url' ) ); ?>" type="url" placeholder="https://www.audible.com/pd/..." value="<?php echo esc_attr( $link_url ); ?>">
			<br><small><?php esc_html_e( 'Paste your Audible book URL here. It is only used as the click-through link on the cover and title — it is never fetched.', 'audible-currently-reading' ); ?></small>
		</p>
		<p><em><?php esc_html_e( 'Looked-up details (edit manually if needed):', 'audible-currently-reading' ); ?></em></p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'book_title' ) ); ?>"><?php esc_html_e( 'Book Title:', 'audible-currently-reading' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'book_title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'book_title' ) ); ?>" type="text" value="<?php echo esc_attr( $book_title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'author' ) ); ?>"><?php esc_html_e( 'Author(s):', 'audible-currently-reading' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'author' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'author' ) ); ?>" type="text" value="<?php echo esc_attr( $author ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'series' ) ); ?>"><?php esc_html_e( 'Series:', 'audible-currently-reading' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'series' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'series' ) ); ?>" type="text" value="<?php echo esc_attr( $series ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'cover_url' ) ); ?>"><?php esc_html_e( 'Cover Image URL:', 'audible-currently-reading' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'cover_url' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'cover_url' ) ); ?>" type="url" value="<?php echo esc_attr( $cover_url ); ?>">
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		$instance                  = array();
		$instance['title']         = sanitize_text_field( $new_instance['title'] );
		$instance['label']         = sanitize_text_field( $new_instance['label'] );
		$instance['search_title']  = sanitize_text_field( $new_instance['search_title'] );
		$instance['search_author'] = sanitize_text_field( $new_instance['search_author'] );
		$instance['link_url']      = esc_url_raw( trim( $new_instance['link_url'] ) );
		$instance['book_title']    = sanitize_text_field( $new_instance['book_title'] );
		$instance['author']        = sanitize_text_field( $new_instance['author'] );
		$instance['series']        = sanitize_text_field( $new_instance['series'] );
		$instance['cover_url']     = esc_url_raw( trim( $new_instance['cover_url'] ) );
		$instance['fetch_error']   = '';

		$old_search    = ( isset( $old_instance['search_title'] ) ? $old_instance['search_title'] : '' ) . '|' . ( isset( $old_instance['search_author'] ) ? $old_instance['search_author'] : '' );
		$new_search    = $instance['search_title'] . '|' . $instance['search_author'];
		$search_changed = $new_search !== $old_search;
		$force_refetch  = ! empty( $new_instance['refetch'] );

		if ( ! empty( $instance['search_title'] ) && ( $search_changed || $force_refetch ) ) {
			$data = audible_crw_lookup_book_data( $instance['search_title'], $instance['search_author'] );
			if ( is_wp_error( $data ) ) {
				$instance['fetch_error'] = $data->get_error_message();
			} else {
				if ( ! empty( $data['title'] ) ) {
					$instance['book_title'] = $data['title'];
				}
				if ( ! empty( $data['author'] ) ) {
					$instance['author'] = $data['author'];
				}
				if ( ! empty( $data['cover_url'] ) ) {
					$instance['cover_url'] = $data['cover_url'];
				}
			}
		}

		return $instance;
	}
}

function audible_crw_register_widget() {
	register_widget( 'Audible_Currently_Reading_Widget' );
}
add_action( 'widgets_init', 'audible_crw_register_widget' );

function audible_crw_enqueue_styles() {
	wp_enqueue_style(
		'audible-currently-reading',
		plugins_url( 'assets/style.css', __FILE__ ),
		array(),
		'1.1.0'
	);
}
add_action( 'wp_enqueue_scripts', 'audible_crw_enqueue_styles' );
