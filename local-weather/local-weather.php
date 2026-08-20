<?php
/**
 * Plugin Name: Local Weather Widget
 * Description: A sidebar widget that shows the current weather at a location you type in — temperature, conditions, and a simple icon, via Open-Meteo's free API.
 * Version: 1.0.0
 * Author: Mike Williams
 * License: GPL-2.0-or-later
 * Text Domain: local-weather-widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turn a typed place name into coordinates via Open-Meteo's free, keyless
 * geocoding API. Runs once, when the location field changes, same pattern
 * as the book-title lookup in the Audible widget.
 *
 * @param string $query e.g. "Portland, OR"
 * @return array|WP_Error {name, lat, lon}
 */
function lw_geocode_location( $query ) {
	$query = trim( $query );
	if ( '' === $query ) {
		return new WP_Error( 'lw_no_query', __( 'Enter a city to search for.', 'local-weather-widget' ) );
	}

	$url = add_query_arg(
		array(
			'name'     => $query,
			'count'    => 1,
			'language' => 'en',
			'format'   => 'json',
		),
		'https://geocoding-api.open-meteo.com/v1/search'
	);

	$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
		return new WP_Error( 'lw_geocode_http_error', __( 'The location search is temporarily unavailable. Try again in a moment.', 'local-weather-widget' ) );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body['results'][0] ) ) {
		return new WP_Error( 'lw_no_match', __( 'No location found for that search. Try adding a state or country, e.g. "Portland, OR".', 'local-weather-widget' ) );
	}

	$place = $body['results'][0];

	if ( ! empty( $place['admin1'] ) ) {
		$name = sprintf( '%s, %s', $place['name'], $place['admin1'] );
	} elseif ( ! empty( $place['country'] ) ) {
		$name = sprintf( '%s, %s', $place['name'], $place['country'] );
	} else {
		$name = $place['name'];
	}

	return array(
		'name' => sanitize_text_field( $name ),
		'lat'  => (float) $place['latitude'],
		'lon'  => (float) $place['longitude'],
	);
}

/**
 * Map an Open-Meteo/WMO weather code to a short label and emoji.
 *
 * @param int $code
 * @param bool $is_day
 * @return array {text, emoji}
 */
function lw_weather_code_map( $code, $is_day ) {
	$map = array(
		0  => array( __( 'Clear sky', 'local-weather-widget' ), $is_day ? '☀️' : '🌙' ),
		1  => array( __( 'Mainly clear', 'local-weather-widget' ), $is_day ? '🌤️' : '🌙' ),
		2  => array( __( 'Partly cloudy', 'local-weather-widget' ), '⛅' ),
		3  => array( __( 'Overcast', 'local-weather-widget' ), '☁️' ),
		45 => array( __( 'Fog', 'local-weather-widget' ), '🌫️' ),
		48 => array( __( 'Fog', 'local-weather-widget' ), '🌫️' ),
		51 => array( __( 'Light drizzle', 'local-weather-widget' ), '🌦️' ),
		53 => array( __( 'Drizzle', 'local-weather-widget' ), '🌦️' ),
		55 => array( __( 'Dense drizzle', 'local-weather-widget' ), '🌦️' ),
		56 => array( __( 'Freezing drizzle', 'local-weather-widget' ), '🌧️' ),
		57 => array( __( 'Freezing drizzle', 'local-weather-widget' ), '🌧️' ),
		61 => array( __( 'Light rain', 'local-weather-widget' ), '🌧️' ),
		63 => array( __( 'Rain', 'local-weather-widget' ), '🌧️' ),
		65 => array( __( 'Heavy rain', 'local-weather-widget' ), '🌧️' ),
		66 => array( __( 'Freezing rain', 'local-weather-widget' ), '🌧️' ),
		67 => array( __( 'Freezing rain', 'local-weather-widget' ), '🌧️' ),
		71 => array( __( 'Light snow', 'local-weather-widget' ), '🌨️' ),
		73 => array( __( 'Snow', 'local-weather-widget' ), '🌨️' ),
		75 => array( __( 'Heavy snow', 'local-weather-widget' ), '❄️' ),
		77 => array( __( 'Snow grains', 'local-weather-widget' ), '🌨️' ),
		80 => array( __( 'Rain showers', 'local-weather-widget' ), '🌦️' ),
		81 => array( __( 'Rain showers', 'local-weather-widget' ), '🌦️' ),
		82 => array( __( 'Violent rain showers', 'local-weather-widget' ), '⛈️' ),
		85 => array( __( 'Snow showers', 'local-weather-widget' ), '🌨️' ),
		86 => array( __( 'Snow showers', 'local-weather-widget' ), '🌨️' ),
		95 => array( __( 'Thunderstorm', 'local-weather-widget' ), '⛈️' ),
		96 => array( __( 'Thunderstorm with hail', 'local-weather-widget' ), '⛈️' ),
		99 => array( __( 'Thunderstorm with hail', 'local-weather-widget' ), '⛈️' ),
	);

	if ( isset( $map[ $code ] ) ) {
		return array(
			'text'  => $map[ $code ][0],
			'emoji' => $map[ $code ][1],
		);
	}

	return array(
		'text'  => __( 'Unknown', 'local-weather-widget' ),
		'emoji' => '🌡️',
	);
}

/**
 * Fetch current conditions for a coordinate, cached in a transient since
 * (unlike a book cover) this needs to be reasonably fresh on every page
 * view, not just re-fetched when the admin saves settings.
 *
 * @param float  $lat
 * @param float  $lon
 * @param string $unit   'fahrenheit' or 'celsius'
 * @param string $cache_key Unique per widget instance, so multiple weather widgets don't collide.
 * @return array|WP_Error
 */
function lw_get_current_weather( $lat, $lon, $unit, $cache_key ) {
	$transient_key = 'lw_weather_' . md5( $cache_key . '|' . $lat . '|' . $lon . '|' . $unit );
	$cached        = get_transient( $transient_key );
	if ( false !== $cached ) {
		return $cached;
	}

	$url = add_query_arg(
		array(
			'latitude'         => $lat,
			'longitude'        => $lon,
			'current'          => 'temperature_2m,apparent_temperature,weather_code,is_day',
			'temperature_unit' => $unit,
			'timezone'         => 'auto',
		),
		'https://api.open-meteo.com/v1/forecast'
	);

	$response = wp_remote_get( $url, array( 'timeout' => 8 ) );

	if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
		// Serve the last known-good reading rather than showing nothing.
		$fallback = get_option( 'lw_last_known_' . $cache_key );
		if ( $fallback ) {
			return $fallback;
		}
		return new WP_Error( 'lw_forecast_error', __( 'Weather is temporarily unavailable.', 'local-weather-widget' ) );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $body['current'] ) ) {
		$fallback = get_option( 'lw_last_known_' . $cache_key );
		if ( $fallback ) {
			return $fallback;
		}
		return new WP_Error( 'lw_forecast_empty', __( 'Weather is temporarily unavailable.', 'local-weather-widget' ) );
	}

	$current  = $body['current'];
	$code_map = lw_weather_code_map( (int) $current['weather_code'], ! empty( $current['is_day'] ) );

	$data = array(
		'temp'       => round( $current['temperature_2m'] ),
		'feels_like' => round( $current['apparent_temperature'] ),
		'condition'  => $code_map['text'],
		'emoji'      => $code_map['emoji'],
		'unit'       => 'fahrenheit' === $unit ? 'F' : 'C',
	);

	set_transient( $transient_key, $data, 15 * MINUTE_IN_SECONDS );
	update_option( 'lw_last_known_' . $cache_key, $data, false );

	return $data;
}

class Local_Weather_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'local_weather',
			__( 'Local Weather', 'local-weather-widget' ),
			array(
				'description'                 => __( 'Shows current conditions for a location you type in.', 'local-weather-widget' ),
				'customize_selective_refresh' => true,
			)
		);
	}

	public function widget( $args, $instance ) {
		$title    = ! empty( $instance['title'] ) ? $instance['title'] : '';
		$label    = ! empty( $instance['label'] ) ? $instance['label'] : __( 'Local Weather', 'local-weather-widget' );
		$lat      = isset( $instance['lat'] ) ? (float) $instance['lat'] : null;
		$lon      = isset( $instance['lon'] ) ? (float) $instance['lon'] : null;
		$loc_name = ! empty( $instance['location_name'] ) ? $instance['location_name'] : '';
		$unit     = ! empty( $instance['unit'] ) && 'celsius' === $instance['unit'] ? 'celsius' : 'fahrenheit';

		if ( ! $lat || ! $lon ) {
			return;
		}

		$weather = lw_get_current_weather( $lat, $lon, $unit, $args['widget_id'] );
		if ( is_wp_error( $weather ) ) {
			return;
		}

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput

		if ( ! empty( $title ) ) {
			echo $args['before_title'] . esc_html( $title ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput
		}

		echo '<div class="lw-card">';

		if ( ! empty( $label ) ) {
			echo '<div class="lw-label">' . esc_html( $label ) . '</div>';
		}

		echo '<div class="lw-emoji" aria-hidden="true">' . esc_html( $weather['emoji'] ) . '</div>';
		echo '<div class="lw-temp">' . esc_html( $weather['temp'] ) . '&deg;' . esc_html( $weather['unit'] ) . '</div>';
		echo '<div class="lw-condition">' . esc_html( $weather['condition'] ) . '</div>';
		echo '<div class="lw-feels-like">' . esc_html__( 'Feels like', 'local-weather-widget' ) . ' ' . esc_html( $weather['feels_like'] ) . '&deg;' . esc_html( $weather['unit'] ) . '</div>';

		if ( ! empty( $loc_name ) ) {
			echo '<div class="lw-location">' . esc_html( $loc_name ) . '</div>';
		}

		echo '</div>'; // .lw-card

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function form( $instance ) {
		$title          = ! empty( $instance['title'] ) ? $instance['title'] : '';
		$label          = ! empty( $instance['label'] ) ? $instance['label'] : __( 'Local Weather', 'local-weather-widget' );
		$search_location = ! empty( $instance['search_location'] ) ? $instance['search_location'] : '';
		$unit           = ! empty( $instance['unit'] ) && 'celsius' === $instance['unit'] ? 'celsius' : 'fahrenheit';
		$location_name  = ! empty( $instance['location_name'] ) ? $instance['location_name'] : '';
		$lat            = isset( $instance['lat'] ) ? $instance['lat'] : '';
		$lon            = isset( $instance['lon'] ) ? $instance['lon'] : '';
		$fetch_error    = ! empty( $instance['fetch_error'] ) ? $instance['fetch_error'] : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Widget Title:', 'local-weather-widget' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'label' ) ); ?>"><?php esc_html_e( 'Heading:', 'local-weather-widget' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'label' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'label' ) ); ?>" type="text" value="<?php echo esc_attr( $label ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'search_location' ) ); ?>"><?php esc_html_e( 'Location to search for:', 'local-weather-widget' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'search_location' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'search_location' ) ); ?>" type="text" placeholder="Portland, OR" value="<?php echo esc_attr( $search_location ); ?>">
			<br><small><?php esc_html_e( 'City name works best with a state/country if it\'s a common name, e.g. "Portland, OR" vs "Portland, ME". Looked up automatically when you save.', 'local-weather-widget' ); ?></small>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'unit' ) ); ?>"><?php esc_html_e( 'Units:', 'local-weather-widget' ); ?></label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'unit' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'unit' ) ); ?>">
				<option value="fahrenheit" <?php selected( $unit, 'fahrenheit' ); ?>><?php esc_html_e( 'Fahrenheit', 'local-weather-widget' ); ?></option>
				<option value="celsius" <?php selected( $unit, 'celsius' ); ?>><?php esc_html_e( 'Celsius', 'local-weather-widget' ); ?></option>
			</select>
		</p>
		<p>
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( 'refetch' ) ); ?>" value="1">
				<?php esc_html_e( 'Re-run the location search on save', 'local-weather-widget' ); ?>
			</label>
		</p>
		<?php if ( $fetch_error ) : ?>
			<p style="color:#b32d2e;"><?php echo esc_html( $fetch_error ); ?></p>
		<?php endif; ?>
		<p><em><?php esc_html_e( 'Resolved location (edit manually if needed):', 'local-weather-widget' ); ?></em></p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'location_name' ) ); ?>"><?php esc_html_e( 'Display Name:', 'local-weather-widget' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'location_name' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'location_name' ) ); ?>" type="text" value="<?php echo esc_attr( $location_name ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'lat' ) ); ?>"><?php esc_html_e( 'Latitude:', 'local-weather-widget' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'lat' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'lat' ) ); ?>" type="text" value="<?php echo esc_attr( $lat ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'lon' ) ); ?>"><?php esc_html_e( 'Longitude:', 'local-weather-widget' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'lon' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'lon' ) ); ?>" type="text" value="<?php echo esc_attr( $lon ); ?>">
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		$instance                    = array();
		$instance['title']           = sanitize_text_field( $new_instance['title'] );
		$instance['label']           = sanitize_text_field( $new_instance['label'] );
		$instance['search_location'] = sanitize_text_field( $new_instance['search_location'] );
		$instance['unit']            = 'celsius' === $new_instance['unit'] ? 'celsius' : 'fahrenheit';
		$instance['location_name']   = sanitize_text_field( $new_instance['location_name'] );
		$instance['lat']             = is_numeric( $new_instance['lat'] ) ? (float) $new_instance['lat'] : '';
		$instance['lon']             = is_numeric( $new_instance['lon'] ) ? (float) $new_instance['lon'] : '';
		$instance['fetch_error']     = '';

		$old_search     = isset( $old_instance['search_location'] ) ? $old_instance['search_location'] : '';
		$search_changed = $instance['search_location'] !== $old_search;
		$force_refetch  = ! empty( $new_instance['refetch'] );

		if ( ! empty( $instance['search_location'] ) && ( $search_changed || $force_refetch ) ) {
			$place = lw_geocode_location( $instance['search_location'] );
			if ( is_wp_error( $place ) ) {
				$instance['fetch_error'] = $place->get_error_message();
			} else {
				$instance['location_name'] = $place['name'];
				$instance['lat']           = $place['lat'];
				$instance['lon']           = $place['lon'];
			}
		}

		return $instance;
	}
}

function lw_register_widget() {
	register_widget( 'Local_Weather_Widget' );
}
add_action( 'widgets_init', 'lw_register_widget' );

function lw_enqueue_styles() {
	wp_enqueue_style(
		'local-weather-widget',
		plugins_url( 'assets/style.css', __FILE__ ),
		array(),
		'1.0.0'
	);
}
add_action( 'wp_enqueue_scripts', 'lw_enqueue_styles' );
