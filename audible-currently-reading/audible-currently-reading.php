<?php
/**
 * Plugin Name: Audible Currently Reading Widget
 * Description: A sidebar widget that shows the audiobook you're currently listening to on Audible — cover, title, series, and author(s) — pulled automatically from a pasted Audible product URL.
 * Version: 1.0.0
 * Author: Mike Williams
 * License: GPL-2.0-or-later
 * Text Domain: audible-currently-reading
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetch title, author, series, and cover image from an Audible product page.
 *
 * @param string $url Audible product URL.
 * @return array|WP_Error
 */
function audible_crw_fetch_book_data( $url ) {
	$host = wp_parse_url( $url, PHP_URL_HOST );
	if ( ! $host || ! preg_match( '/(^|\.)audible\.[a-z.]+$/i', $host ) ) {
		return new WP_Error( 'acrw_invalid_url', __( 'That does not look like an Audible URL.', 'audible-currently-reading' ) );
	}

	$response = wp_remote_get(
		$url,
		array(
			'timeout'    => 15,
			'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
			'headers'    => array(
				'Accept-Language' => 'en-US,en;q=0.9',
			),
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
				__( 'Audible returned an unexpected response (HTTP %d).', 'audible-currently-reading' ),
				$code
			)
		);
	}

	$body = wp_remote_retrieve_body( $response );
	if ( empty( $body ) ) {
		return new WP_Error( 'acrw_empty_body', __( 'Audible returned an empty page.', 'audible-currently-reading' ) );
	}

	$data = array(
		'title'     => '',
		'author'    => '',
		'series'    => '',
		'cover_url' => '',
	);

	// Title from the Open Graph tag (clean, no "Audiobook by ..." suffix).
	if ( preg_match( '/<meta\s+property="og:title"\s+content="([^"]*)"/i', $body, $m ) ) {
		$data['title'] = html_entity_decode( $m[1], ENT_QUOTES );
	}

	// Audible embeds structured JSON blobs on the page (schema.org Product/Audiobook,
	// plus an internal <adbl-product-metadata> block with authors/narrators/series).
	if ( preg_match_all( '/<script type="application\/(?:ld\+json|json)">(.*?)<\/script>/is', $body, $matches ) ) {
		foreach ( $matches[1] as $json_str ) {
			$json = json_decode( trim( $json_str ), true );
			if ( ! is_array( $json ) ) {
				continue;
			}

			// JSON-LD blocks are wrapped in a list; the metadata blobs are not.
			$product = isset( $json[0] ) ? $json[0] : $json;

			if ( isset( $product['@type'] ) && 'Product' === $product['@type'] && ! empty( $product['image'] ) ) {
				$data['cover_url'] = $product['image'];
			}

			if ( isset( $product['@type'] ) && 'Audiobook' === $product['@type'] ) {
				if ( empty( $data['title'] ) && ! empty( $product['name'] ) ) {
					$data['title'] = $product['name'];
				}
				if ( ! empty( $product['author'] ) && is_array( $product['author'] ) ) {
					$names = wp_list_pluck( $product['author'], 'name' );
					$data['author'] = implode( ', ', array_filter( $names ) );
				}
			}

			if ( empty( $data['author'] ) && ! empty( $json['authors'] ) && is_array( $json['authors'] ) ) {
				$names = wp_list_pluck( $json['authors'], 'name' );
				$data['author'] = implode( ', ', array_filter( $names ) );
			}

			if ( ! empty( $json['series'] ) && is_array( $json['series'] ) ) {
				$parts = array();
				foreach ( $json['series'] as $s ) {
					if ( empty( $s['name'] ) ) {
						continue;
					}
					$parts[] = ! empty( $s['part'] ) ? sprintf( '%s (%s)', $s['name'], $s['part'] ) : $s['name'];
				}
				if ( $parts ) {
					$data['series'] = implode( ', ', $parts );
				}
			}
		}
	}

	// Fallback cover: Open Graph image. This one has a "LISTENING ON" share-card
	// overlay baked in, so it's only used if we couldn't find a clean cover above.
	if ( empty( $data['cover_url'] ) && preg_match( '/<meta\s+property="og:image"\s+content="([^"]*)"/i', $body, $m ) ) {
		$data['cover_url'] = html_entity_decode( $m[1], ENT_QUOTES );
	}

	if ( empty( $data['title'] ) && empty( $data['cover_url'] ) ) {
		return new WP_Error( 'acrw_no_data', __( 'Could not find book details on that page. Double-check the URL, or fill in the fields manually below.', 'audible-currently-reading' ) );
	}

	return $data;
}

class Audible_Currently_Reading_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'audible_currently_reading',
			__( 'Currently Reading (Audible)', 'audible-currently-reading' ),
			array(
				'description'                 => __( 'Shows the audiobook you are currently listening to, pulled from an Audible URL.', 'audible-currently-reading' ),
				'customize_selective_refresh' => true,
			)
		);
	}

	public function widget( $args, $instance ) {
		$title      = ! empty( $instance['title'] ) ? $instance['title'] : '';
		$label      = ! empty( $instance['label'] ) ? $instance['label'] : __( 'Currently Reading', 'audible-currently-reading' );
		$book_url   = ! empty( $instance['book_url'] ) ? $instance['book_url'] : '';
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

		if ( ! empty( $cover_url ) ) {
			if ( ! empty( $book_url ) ) {
				echo '<a class="acrw-cover-link" href="' . esc_url( $book_url ) . '" target="_blank" rel="noopener noreferrer">';
			}
			echo '<img class="acrw-cover" src="' . esc_url( $cover_url ) . '" alt="' . esc_attr( $book_title ) . '" loading="lazy" />';
			if ( ! empty( $book_url ) ) {
				echo '</a>';
			}
		}

		echo '<div class="acrw-details">';

		if ( ! empty( $label ) ) {
			echo '<div class="acrw-label">' . esc_html( $label ) . '</div>';
		}

		if ( ! empty( $book_title ) ) {
			echo '<div class="acrw-title">';
			if ( ! empty( $book_url ) ) {
				echo '<a href="' . esc_url( $book_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $book_title ) . '</a>';
			} else {
				echo esc_html( $book_title );
			}
			echo '</div>';
		}

		if ( ! empty( $series ) ) {
			echo '<div class="acrw-series">' . esc_html( $series ) . '</div>';
		}

		if ( ! empty( $author ) ) {
			echo '<div class="acrw-author">' . esc_html__( 'by', 'audible-currently-reading' ) . ' ' . esc_html( $author ) . '</div>';
		}

		echo '</div>'; // .acrw-details
		echo '</div>'; // .acrw-card

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function form( $instance ) {
		$title       = ! empty( $instance['title'] ) ? $instance['title'] : __( "What I'm Listening To", 'audible-currently-reading' );
		$label       = ! empty( $instance['label'] ) ? $instance['label'] : __( 'Currently Reading', 'audible-currently-reading' );
		$book_url    = ! empty( $instance['book_url'] ) ? $instance['book_url'] : '';
		$book_title  = ! empty( $instance['book_title'] ) ? $instance['book_title'] : '';
		$author      = ! empty( $instance['author'] ) ? $instance['author'] : '';
		$series      = ! empty( $instance['series'] ) ? $instance['series'] : '';
		$cover_url   = ! empty( $instance['cover_url'] ) ? $instance['cover_url'] : '';
		$fetch_error = ! empty( $instance['fetch_error'] ) ? $instance['fetch_error'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Widget Title:', 'audible-currently-reading' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'label' ) ); ?>"><?php esc_html_e( 'Badge Label:', 'audible-currently-reading' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'label' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'label' ) ); ?>" type="text" value="<?php echo esc_attr( $label ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'book_url' ) ); ?>"><?php esc_html_e( 'Audible Book URL:', 'audible-currently-reading' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'book_url' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'book_url' ) ); ?>" type="url" placeholder="https://www.audible.com/pd/..." value="<?php echo esc_attr( $book_url ); ?>">
			<br><small><?php esc_html_e( 'Paste the Audible product page URL. Cover, title, author, and series are fetched automatically when you save.', 'audible-currently-reading' ); ?></small>
		</p>
		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'refetch' ) ); ?>" value="1">
				<?php esc_html_e( 'Re-fetch details from Audible on save', 'audible-currently-reading' ); ?>
			</label>
		</p>
		<?php if ( $fetch_error ) : ?>
			<p style="color:#b32d2e;"><?php echo esc_html( $fetch_error ); ?></p>
		<?php endif; ?>
		<p><em><?php esc_html_e( 'Fetched details (edit manually if needed):', 'audible-currently-reading' ); ?></em></p>
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
		$instance                = array();
		$instance['title']       = sanitize_text_field( $new_instance['title'] );
		$instance['label']       = sanitize_text_field( $new_instance['label'] );
		$instance['book_url']    = esc_url_raw( trim( $new_instance['book_url'] ) );
		$instance['book_title']  = sanitize_text_field( $new_instance['book_title'] );
		$instance['author']      = sanitize_text_field( $new_instance['author'] );
		$instance['series']      = sanitize_text_field( $new_instance['series'] );
		$instance['cover_url']   = esc_url_raw( trim( $new_instance['cover_url'] ) );
		$instance['fetch_error'] = '';

		$old_url       = isset( $old_instance['book_url'] ) ? $old_instance['book_url'] : '';
		$url_changed   = $instance['book_url'] !== $old_url;
		$force_refetch = ! empty( $new_instance['refetch'] );

		if ( ! empty( $instance['book_url'] ) && ( $url_changed || $force_refetch ) ) {
			$data = audible_crw_fetch_book_data( $instance['book_url'] );
			if ( is_wp_error( $data ) ) {
				$instance['fetch_error'] = $data->get_error_message();
			} else {
				if ( ! empty( $data['title'] ) ) {
					$instance['book_title'] = sanitize_text_field( $data['title'] );
				}
				if ( ! empty( $data['author'] ) ) {
					$instance['author'] = sanitize_text_field( $data['author'] );
				}
				if ( ! empty( $data['series'] ) ) {
					$instance['series'] = sanitize_text_field( $data['series'] );
				}
				if ( ! empty( $data['cover_url'] ) ) {
					$instance['cover_url'] = esc_url_raw( $data['cover_url'] );
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
		'1.0.0'
	);
}
add_action( 'wp_enqueue_scripts', 'audible_crw_enqueue_styles' );
