<?php
/**
 * GitHub-baserad automatisk uppdatering.
 *
 * Kollar GitHub Releases för nya versioner och integrerar
 * med WordPress plugin-uppdateringssystem.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CoTranslate_Updater {

	/**
	 * GitHub-repo (owner/repo).
	 */
	const GITHUB_REPO = 'Reklam-Co-in-Sweden-AB/CoTranslate';

	/**
	 * Aktuell plugin-version.
	 */
	private $current_version;

	/**
	 * Plugin-basename (cotranslate/cotranslate.php).
	 */
	private $plugin_basename;

	/**
	 * Plugin-slug.
	 */
	private $plugin_slug = 'cotranslate';

	/**
	 * Cachad release-info.
	 */
	private $github_response = null;

	public function __construct() {
		$this->current_version = COTRANSLATE_VERSION;
		$this->plugin_basename = COTRANSLATE_PLUGIN_BASENAME;
	}

	/**
	 * Registrera hooks.
	 */
	public function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );
	}

	/**
	 * Kolla GitHub för ny version.
	 *
	 * @param object $transient WordPress update transient.
	 * @return object Uppdaterad transient.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_latest_release();

		if ( ! $release ) {
			return $transient;
		}

		$remote_version = ltrim( $release['tag_name'], 'v' );

		if ( version_compare( $remote_version, $this->current_version, '>' ) ) {
			$download_url = $this->get_download_url( $release );

			if ( $download_url ) {
				$transient->response[ $this->plugin_basename ] = (object) array(
					'slug'        => $this->plugin_slug,
					'plugin'      => $this->plugin_basename,
					'new_version' => $remote_version,
					'url'         => 'https://github.com/' . self::GITHUB_REPO,
					'package'     => $download_url,
					'icons'       => array(),
					'banners'     => array(),
				);
			}
		}

		return $transient;
	}

	/**
	 * Visa plugin-info i uppdateringsdialogen.
	 *
	 * @param false|object|array $result Resultat.
	 * @param string             $action API-action.
	 * @param object             $args   Argument.
	 * @return false|object Plugininfo eller false.
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( $this->plugin_slug !== ( $args->slug ?? '' ) ) {
			return $result;
		}

		$release = $this->get_latest_release();

		if ( ! $release ) {
			return $result;
		}

		$remote_version = ltrim( $release['tag_name'], 'v' );

		return (object) array(
			'name'            => 'CoTranslate',
			'slug'            => $this->plugin_slug,
			'version'         => $remote_version,
			'author'          => '<a href="https://coscribe.se">Coscribe</a>',
			'homepage'        => 'https://github.com/' . self::GITHUB_REPO,
			'requires'        => '6.0',
			'tested'          => '6.8',
			'requires_php'    => '8.0',
			'download_link'   => $this->get_download_url( $release ),
			'sections'        => array(
				'description'  => 'Automatisk AI-driven översättning med DeepL API och valfritt Claude. Stöd för WooCommerce, page builders och manuella overrides.',
				'changelog'    => nl2br( esc_html( $release['body'] ?? 'Inga ändringsnoteringar.' ) ),
			),
			'last_updated'    => $release['published_at'] ?? '',
		);
	}

	/**
	 * Fixa mappnamn efter installation.
	 *
	 * GitHub-zippar har formatet "RepoName-main/" — vi byter till "CoTranslate/".
	 *
	 * @param bool  $response   Install-resultat.
	 * @param array $hook_extra Extra data.
	 * @param array $result     Installationsresultat.
	 * @return array Uppdaterat resultat.
	 */
	public function after_install( $response, $hook_extra, $result ) {
		global $wp_filesystem;

		// Kontrollera att det är vårt plugin
		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $result;
		}

		$install_directory = plugin_dir_path( dirname( __FILE__ ) );
		$wp_filesystem->move( $result['destination'], $install_directory );
		$result['destination'] = $install_directory;

		// Aktivera pluginet igen
		activate_plugin( $this->plugin_basename );

		return $result;
	}

	/**
	 * Hämta senaste release från GitHub.
	 *
	 * @return array|null Release-data eller null.
	 */
	private function get_latest_release() {
		if ( null !== $this->github_response ) {
			return $this->github_response;
		}

		// Cacha i 6 timmar
		$cache_key = 'cotranslate_github_release';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			$this->github_response = $cached;
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github.v3+json',
					'User-Agent' => 'CoTranslate/' . $this->current_version,
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->github_response = null;
			return null;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $release['tag_name'] ) ) {
			$this->github_response = null;
			return null;
		}

		set_transient( $cache_key, $release, 6 * HOUR_IN_SECONDS );

		$this->github_response = $release;
		return $release;
	}

	/**
	 * Hämta nedladdnings-URL för release.
	 *
	 * Prioriterar .zip-asset om den finns, annars zipball.
	 *
	 * @param array $release Release-data.
	 * @return string|null Nedladdnings-URL.
	 */
	private function get_download_url( $release ) {
		// Kolla om det finns en uppladdad .zip som asset
		if ( ! empty( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( preg_match( '/\.zip$/i', $asset['name'] ) ) {
					return $asset['browser_download_url'];
				}
			}
		}

		// Fallback: GitHub zipball (automatisk zip av repot)
		return $release['zipball_url'] ?? null;
	}
}
