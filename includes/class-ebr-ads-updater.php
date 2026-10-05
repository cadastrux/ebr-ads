<?php
/**
 * Atualizações a partir das releases do GitHub.
 *
 * Fluxo:
 *   1. Um push em `main` com versão nova em ebr-ads.php dispara a GitHub
 *      Action (.github/workflows/release.yml), que publica a release vX.Y.Z
 *      com o asset `ebr-ads.zip` (pasta ebr-ads/ na raiz do zip).
 *   2. O cabeçalho `Update URI` do plugin aponta para github.com; o core então
 *      chama o filtro `update_plugins_github.com`, onde respondemos com a
 *      versão da última release. Daí em diante é o fluxo normal do WordPress:
 *      aviso na tela de Plugins, "Atualizar agora" e atualização automática.
 *
 * Única conexão externa do plugin. Somente leitura, sem token, e sem enviar
 * dado do site: o User-Agent padrão do WordPress inclui a URL do site, então
 * usamos um próprio.
 *
 * @package EBR_Ads
 */

defined( 'ABSPATH' ) || exit;

class EBR_Ads_Updater {

	const REPO      = 'cadastrux/ebr-ads';
	const ASSET     = 'ebr-ads.zip';
	const CACHE     = 'ebr_ads_github_release';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/** Em caso de erro, espera menos antes de tentar de novo — mas não a cada página. */
	const ERROR_TTL = HOUR_IN_SECONDS;

	/**
	 * Registra os hooks.
	 */
	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_force_check' ) );
	}

	/**
	 * Responde ao core se há versão nova.
	 *
	 * @param array|false $update      Resposta de outro filtro, ou false.
	 * @param array       $plugin_data Cabeçalhos do plugin.
	 * @param string      $plugin_file Basename do plugin.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		// O filtro roda para TODO plugin com Update URI em github.com.
		if ( plugin_basename( EBR_ADS_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $update;
		}

		// Com versão igual ou menor o core registra "sem atualização", o que
		// também habilita o botão de atualização automática na lista.
		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => 'ebr-ads',
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'new_version'  => $release['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $release['package'],
			'requires'     => isset( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '',
			'requires_php' => isset( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '',
		);
	}

	/**
	 * Conteúdo do modal "Ver detalhes" na tela de Plugins/Atualizações.
	 *
	 * @param false|object|array $result Resultado de outro filtro.
	 * @param string             $action Ação da plugins_api.
	 * @param object             $args   Argumentos.
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'ebr-ads' !== $args->slug ) {
			return $result;
		}

		$release = self::latest_release();
		if ( ! $release ) {
			return $result;
		}

		// O core passa as seções por wp_kses ao exibir; ainda assim, o corpo da
		// release é texto do GitHub e sai escapado.
		$notes = '' !== $release['notes']
			? '<p>' . nl2br( esc_html( $release['notes'] ) ) . '</p>'
			: '<p>' . esc_html__( 'Sem notas para esta versão.', 'ebr-ads' ) . '</p>';

		return (object) array(
			'name'          => 'EBR Ads',
			'slug'          => 'ebr-ads',
			'version'       => $release['version'],
			'author'        => 'EBR Network',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $release['package'],
			'last_updated'  => $release['date'],
			'sections'      => array(
				'changelog' => $notes,
			),
		);
	}

	/**
	 * Última release publicada, com cache.
	 *
	 * @return array|null { version, package, notes, date }
	 */
	private static function latest_release() {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$release = self::fetch();

		// Falha também é cacheada (como array vazio), para que uma API fora do
		// ar ou o limite de requisições do GitHub não gere uma chamada por página.
		set_site_transient( self::CACHE, $release ? $release : array(), $release ? self::CACHE_TTL : self::ERROR_TTL );

		return $release;
	}

	/**
	 * Consulta a API do GitHub.
	 *
	 * @return array|null
	 */
	private static function fetch() {
		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout'    => 10,
				'user-agent' => 'EBR-Ads-Updater/' . EBR_ADS_VERSION,
				'headers'    => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
			return null;
		}

		// Tag no formato v1.2.3 (ou 1.2.3). Qualquer outra coisa é ignorada.
		$version = ltrim( (string) $data['tag_name'], 'vV' );
		if ( ! preg_match( '/^\d+\.\d+(\.\d+)?$/', $version ) ) {
			return null;
		}

		// Só aceitamos o zip anexado pela Action, e só deste repositório. O
		// zipball automático do GitHub teria a pasta com nome errado
		// (cadastrux-ebr-ads-<sha>) e o plugin seria instalado em outro lugar.
		$package = '';
		$prefix  = 'https://github.com/' . self::REPO . '/releases/download/';
		foreach ( (array) ( isset( $data['assets'] ) ? $data['assets'] : array() ) as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] )
				&& self::ASSET === $asset['name']
				&& 0 === strpos( $asset['browser_download_url'], $prefix ) ) {
				$package = $asset['browser_download_url'];
				break;
			}
		}
		if ( '' === $package ) {
			return null;
		}

		return array(
			'version' => $version,
			'package' => esc_url_raw( $package ),
			'notes'   => isset( $data['body'] ) ? (string) $data['body'] : '',
			'date'    => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
		);
	}

	/**
	 * Descarta o cache.
	 */
	public static function flush() {
		delete_site_transient( self::CACHE );
	}

	/**
	 * "Verificar novamente" em Painel → Atualizações também consulta o GitHub.
	 */
	public static function maybe_force_check() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- só descarta cache, mesmo comportamento do core.
		if ( ! empty( $_GET['force-check'] ) && current_user_can( 'update_plugins' ) ) {
			self::flush();
		}
	}
}
