<?php
/**
 * Språkväljare för CoTranslate.
 *
 * Renderar språkväljare i flera stilar: dropdown, compact, flags, floating.
 * Stöd för shortcode, meny-integration och PHP-funktion.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_Language_Switcher {

	/**
	 * @var CoTranslate_URL_Handler
	 */
	private $url_handler;

	public function __construct( CoTranslate_URL_Handler $url_handler ) {
		$this->url_handler = $url_handler;
	}

	/**
	 * Registrera hooks.
	 */
	public function init() {
		add_shortcode( 'cotranslate_switcher', array( $this, 'render_shortcode' ) );
		add_action( 'wp_footer', array( $this, 'render_floating_switcher' ), 50 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Meny-integration
		$menu_location = get_option( 'cotranslate_menu_switcher_location', '' );
		if ( ! empty( $menu_location ) ) {
			add_filter( 'wp_nav_menu_items', array( $this, 'add_to_menu' ), 20, 2 );
		}

		// Registrera widget
		add_action( 'widgets_init', array( $this, 'register_widget' ) );
	}

	/**
	 * Ladda CSS och JS.
	 */
	public function enqueue_assets() {
		wp_enqueue_style(
			'cotranslate-language-switcher',
			COTRANSLATE_PLUGIN_URL . 'assets/css/language-switcher.css',
			array(),
			COTRANSLATE_VERSION
		);

		wp_enqueue_script(
			'cotranslate-language-switcher',
			COTRANSLATE_PLUGIN_URL . 'assets/js/language-switcher.js',
			array(),
			COTRANSLATE_VERSION,
			true
		);
	}

	/**
	 * Shortcode: [cotranslate_switcher style="dropdown"]
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'style' => 'dropdown',
		), $atts, 'cotranslate_switcher' );

		return $this->render( $atts['style'] );
	}

	/**
	 * Rendera flytande språkväljare.
	 */
	public function render_floating_switcher() {
		if ( is_admin() ) {
			return;
		}

		if ( ! get_option( 'cotranslate_show_floating_switcher', true ) ) {
			return;
		}

		$position = get_option( 'cotranslate_floating_position', 'bottom-right' );

		echo '<div class="cotranslate-floating cotranslate-floating-' . esc_attr( $position ) . '" translate="no">';
		echo $this->render( 'dropdown' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	/**
	 * Lägg till språkväljare i WordPress-meny.
	 */
	public function add_to_menu( $items, $args ) {
		$target_location = get_option( 'cotranslate_menu_switcher_location', '' );

		if ( empty( $target_location ) || $args->theme_location !== $target_location ) {
			return $items;
		}

		$switcher = $this->render( 'compact' );
		$items   .= '<li class="menu-item cotranslate-menu-switcher">' . $switcher . '</li>';

		return $items;
	}

	/**
	 * Registrera WordPress-widget.
	 */
	public function register_widget() {
		register_widget( 'CoTranslate_Switcher_Widget' );
	}

	/**
	 * Rendera språkväljare.
	 *
	 * @param string $style Stil: dropdown, compact, flags, list.
	 * @return string HTML.
	 */
	public function render( $style = 'dropdown' ) {
		$enabled_languages = cotranslate_get_enabled_languages();
		$current_language  = cotranslate_get_current_language();
		$supported         = cotranslate_get_supported_languages();

		if ( count( $enabled_languages ) < 2 ) {
			return '';
		}

		// Bygg bas-URL utan språkprefix (använd raw home_url för att undvika filter-loop)
		$raw_home   = get_option( 'home' );
		$request_uri = $this->url_handler->get_original_request_uri();
		$current_url = $raw_home . $request_uri;

		// Bygg språkdata
		$languages = array();
		foreach ( $enabled_languages as $code ) {
			$data = $supported[ $code ] ?? null;
			if ( ! $data ) {
				continue;
			}

			$languages[] = array(
				'code'    => $code,
				'name'    => $data['native'],
				'flag'    => $data['flag'],
				'url'     => $this->url_handler->get_url_for_language( $current_url, $code ),
				'active'  => $code === $current_language,
			);
		}

		switch ( $style ) {
			case 'compact':
				return $this->render_compact( $languages, $current_language );
			case 'flags':
				return $this->render_flags( $languages );
			case 'list':
				return $this->render_list( $languages );
			case 'dropdown':
			default:
				return $this->render_dropdown( $languages, $current_language );
		}
	}

	/**
	 * Dropdown-stil.
	 */
	private function render_dropdown( array $languages, $current_language ) {
		$current = null;
		foreach ( $languages as $lang ) {
			if ( $lang['active'] ) {
				$current = $lang;
				break;
			}
		}

		if ( ! $current ) {
			return '';
		}

		$html  = '<div class="cotranslate-switcher cotranslate-dropdown" translate="no">';
		$html .= '<button type="button" class="cotranslate-dropdown-toggle" aria-expanded="false" aria-haspopup="true">';
		$html .= '<span class="cotranslate-flag">' . esc_html( $current['flag'] ) . '</span>';
		$html .= '<span class="cotranslate-lang-name">' . esc_html( $current['name'] ) . '</span>';
		$html .= '<span class="cotranslate-arrow">&#9662;</span>';
		$html .= '</button>';
		$html .= '<ul class="cotranslate-dropdown-menu" role="menu">';

		foreach ( $languages as $lang ) {
			$active_class = $lang['active'] ? ' cotranslate-active' : '';
			$aria_current = $lang['active'] ? ' aria-current="true"' : '';

			$html .= '<li role="menuitem">';
			$html .= '<a href="' . esc_url( $lang['url'] ) . '" class="cotranslate-lang-option' . $active_class . '"' . $aria_current . ' hreflang="' . esc_attr( $lang['code'] ) . '">';
			$html .= '<span class="cotranslate-flag">' . esc_html( $lang['flag'] ) . '</span>';
			$html .= '<span class="cotranslate-lang-name">' . esc_html( $lang['name'] ) . '</span>';
			$html .= '</a>';
			$html .= '</li>';
		}

		$html .= '</ul>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Compact-stil (inline med separatorer).
	 */
	private function render_compact( array $languages, $current_language ) {
		$html = '<div class="cotranslate-switcher cotranslate-compact" translate="no">';

		$items = array();
		foreach ( $languages as $lang ) {
			$active_class = $lang['active'] ? ' cotranslate-active' : '';
			$items[] = '<a href="' . esc_url( $lang['url'] ) . '" class="cotranslate-compact-link' . $active_class . '" hreflang="' . esc_attr( $lang['code'] ) . '">'
				. '<span class="cotranslate-flag">' . esc_html( $lang['flag'] ) . '</span>'
				. '<span class="cotranslate-lang-code">' . esc_html( strtoupper( $lang['code'] ) ) . '</span>'
				. '</a>';
		}

		$html .= implode( '<span class="cotranslate-separator">|</span>', $items );
		$html .= '</div>';

		return $html;
	}

	/**
	 * Flags-stil (bara flaggor).
	 */
	private function render_flags( array $languages ) {
		$html = '<div class="cotranslate-switcher cotranslate-flags" translate="no">';

		foreach ( $languages as $lang ) {
			$active_class = $lang['active'] ? ' cotranslate-active' : '';
			$html .= '<a href="' . esc_url( $lang['url'] ) . '" class="cotranslate-flag-link' . $active_class . '" hreflang="' . esc_attr( $lang['code'] ) . '" title="' . esc_attr( $lang['name'] ) . '">';
			$html .= '<span class="cotranslate-flag-large">' . esc_html( $lang['flag'] ) . '</span>';
			$html .= '</a>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * List-stil (vertikal lista).
	 */
	private function render_list( array $languages ) {
		$html = '<ul class="cotranslate-switcher cotranslate-list" translate="no">';

		foreach ( $languages as $lang ) {
			$active_class = $lang['active'] ? ' cotranslate-active' : '';
			$html .= '<li>';
			$html .= '<a href="' . esc_url( $lang['url'] ) . '" class="cotranslate-list-link' . $active_class . '" hreflang="' . esc_attr( $lang['code'] ) . '">';
			$html .= '<span class="cotranslate-flag">' . esc_html( $lang['flag'] ) . '</span>';
			$html .= '<span class="cotranslate-lang-name">' . esc_html( $lang['name'] ) . '</span>';
			$html .= '</a>';
			$html .= '</li>';
		}

		$html .= '</ul>';

		return $html;
	}
}

/**
 * WordPress-widget för språkväljare.
 */
class CoTranslate_Switcher_Widget extends WP_Widget {

	public function __construct() {
		parent::__construct(
			'cotranslate_switcher',
			'CoTranslate Språkväljare',
			array( 'description' => 'Visar en språkväljare för CoTranslate.' )
		);
	}

	public function widget( $args, $instance ) {
		$style = ! empty( $instance['style'] ) ? $instance['style'] : 'dropdown';

		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . esc_html( $instance['title'] ) . $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		$plugin = CoTranslate_Plugin::get_instance();
		if ( $plugin->language_switcher ) {
			echo $plugin->language_switcher->render( $style ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function form( $instance ) {
		$title = ! empty( $instance['title'] ) ? $instance['title'] : '';
		$style = ! empty( $instance['style'] ) ? $instance['style'] : 'dropdown';
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">Titel:</label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text" value="<?php echo esc_attr( $title ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>">Stil:</label>
			<select class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'style' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'style' ) ); ?>">
				<option value="dropdown" <?php selected( $style, 'dropdown' ); ?>>Dropdown</option>
				<option value="compact" <?php selected( $style, 'compact' ); ?>>Compact</option>
				<option value="flags" <?php selected( $style, 'flags' ); ?>>Flaggor</option>
				<option value="list" <?php selected( $style, 'list' ); ?>>Lista</option>
			</select>
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		$instance          = array();
		$instance['title'] = sanitize_text_field( $new_instance['title'] ?? '' );
		$instance['style'] = sanitize_key( $new_instance['style'] ?? 'dropdown' );
		return $instance;
	}
}

/**
 * Template-funktion för temautvecklare.
 *
 * @param string $style Stil: dropdown, compact, flags, list.
 */
function cotranslate_language_switcher( $style = 'dropdown' ) {
	$plugin = CoTranslate_Plugin::get_instance();
	if ( $plugin->language_switcher ) {
		echo $plugin->language_switcher->render( $style ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
