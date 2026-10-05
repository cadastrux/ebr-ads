<?php
/**
 * Atualizações a partir dos tags do GitHub.
 *
 * Para lançar uma versão: altere a versão em ebr-ads.php, faça commit, crie o
 * tag (`git tag v1.2.1`) e envie (`git push origin main --tags`). O WordPress
 * passa a mostrar o aviso clássico de "nova versão disponível".
 *
 * Como funciona:
 *   1. O cabeçalho `Update URI` aponta para github.com; o core então chama o
 *      filtro `update_plugins_github.com`, onde respondemos com o maior tag no
 *      formato vX.Y.Z. A comparação com a versão instalada é do próprio core.
 *   2. O pacote é o zip que o GitHub gera para o tag. A pasta dentro dele vem
 *      como `ebr-ads-1.2.1`; rename_source() a renomeia para `ebr-ads` antes da
 *      instalação, para o WordPress substituir o plugin em vez de criar outro.
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
	const SLUG      = 'ebr-ads';
	const CACHE     = 'ebr_ads_github_tag';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/** Em caso de erro, espera menos antes de tentar de novo — mas não a cada página. */
	const ERROR_TTL = HOUR_IN_SECONDS;

	/**
	 * Registra os hooks.
	 */
	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'rename_source' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_force_check' ) );
	}

	/**
	 * Responde ao core qual é a versão mais nova.
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

		$tag = self::latest_tag();
		if ( ! $tag ) {
			return $update;
		}

		// Versão igual ou menor que a instalada: o core registra "sem
		// atualização", o que também habilita o botão de atualização automática.
		return array(
			'slug'         => self::SLUG,
			'version'      => $tag['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => self::package_url( $tag['name'] ),
			'requires'     => isset( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '',
			'requires_php' => isset( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '',
		);
	}

	/**
	 * Modal "Ver detalhes da versão" — mostra o Changelog do readme.txt do tag.
	 *
	 * @param false|object|array $result Resultado de outro filtro.
	 * @param string             $action Ação da plugins_api.
	 * @param object             $args   Argumentos.
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$tag = self::latest_tag();
		if ( ! $tag ) {
			return $result;
		}

		$notes = self::changelog( $tag );

		return (object) array(
			'name'          => 'EBR Ads',
			'slug'          => self::SLUG,
			'version'       => $tag['version'],
			'author'        => 'EBR Network',
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => self::package_url( $tag['name'] ),
			'sections'      => array(
				// O core passa as seções por wp_kses; o texto vem escapado mesmo assim.
				'changelog' => '' !== $notes
					? '<p>' . nl2br( esc_html( $notes ) ) . '</p>'
					: '<p>' . esc_html__( 'Sem notas para esta versão.', 'ebr-ads' ) . '</p>',
			),
		);
	}

	/**
	 * Renomeia a pasta extraída do zip do GitHub para `ebr-ads`.
	 *
	 * Vale para a atualização pelo painel e também para o envio manual do zip
	 * em Plugins → Adicionar novo, desde que o pacote seja este plugin.
	 *
	 * @param string      $source        Pasta extraída.
	 * @param string      $remote_source Pasta-mãe da extração.
	 * @param WP_Upgrader $upgrader      Upgrader.
	 * @param array       $hook_extra    Contexto da operação.
	 * @return string|WP_Error
	 */
	public static function rename_source( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( is_wp_error( $source ) || self::SLUG === basename( untrailingslashit( $source ) ) ) {
			return $source;
		}

		$is_update = isset( $hook_extra['plugin'] ) && plugin_basename( EBR_ADS_FILE ) === $hook_extra['plugin'];

		if ( ! $is_update ) {
			// Instalação/envio manual: só mexe se o pacote for o EBR Ads.
			$main = trailingslashit( $source ) . 'ebr-ads.php';
			if ( ! file_exists( $main ) ) {
				return $source;
			}
			$headers = get_file_data( $main, array( 'name' => 'Plugin Name' ) );
			if ( 'EBR Ads' !== $headers['name'] ) {
				return $source;
			}
		}

		$target = trailingslashit( $remote_source ) . self::SLUG . '/';

		if ( ! $wp_filesystem || ! $wp_filesystem->move( $source, $target, true ) ) {
			return new WP_Error( 'ebr_ads_rename', __( 'Não foi possível preparar a pasta do EBR Ads para instalação.', 'ebr-ads' ) );
		}

		return $target;
	}

	/**
	 * Maior tag vX.Y.Z do repositório, com cache.
	 *
	 * @return array|null { name: 'v1.2.1', version: '1.2.1' }
	 */
	private static function latest_tag() {
		$cached = get_site_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$tag = self::fetch_latest_tag();

		// Falha também é cacheada (como array vazio), para que uma API fora do
		// ar ou o limite de requisições do GitHub não gere uma chamada por página.
		set_site_transient( self::CACHE, $tag ? $tag : array(), $tag ? self::CACHE_TTL : self::ERROR_TTL );

		return $tag;
	}

	/**
	 * Consulta os tags na API do GitHub e escolhe a maior versão.
	 *
	 * Comparamos versões, não datas: o GitHub não ordena tags por semver.
	 *
	 * @return array|null
	 */
	private static function fetch_latest_tag() {
		$body = self::get( 'https://api.github.com/repos/' . self::REPO . '/tags?per_page=100' );
		$tags = json_decode( (string) $body, true );

		if ( ! is_array( $tags ) ) {
			return null;
		}

		$best = null;
		foreach ( $tags as $tag ) {
			$name = isset( $tag['name'] ) ? (string) $tag['name'] : '';

			// Só v1.2.3 / 1.2.3. Outros tags são ignorados.
			if ( ! preg_match( '/^v?(\d+\.\d+(?:\.\d+)?)$/', $name, $m ) ) {
				continue;
			}
			if ( null === $best || version_compare( $m[1], $best['version'], '>' ) ) {
				$best = array(
					'name'    => $name,
					'version' => $m[1],
				);
			}
		}

		return $best;
	}

	/**
	 * Entrada do Changelog do readme.txt do tag, para o modal de detalhes.
	 *
	 * @param array $tag Tag.
	 * @return string
	 */
	private static function changelog( array $tag ) {
		$readme = self::get( 'https://raw.githubusercontent.com/' . self::REPO . '/' . rawurlencode( $tag['name'] ) . '/readme.txt' );
		if ( ! $readme ) {
			return '';
		}

		// Bloco "= 1.2.1 =" até o próximo "= x =".
		$pattern = '/^=\s*' . preg_quote( $tag['version'], '/' ) . '\s*=\s*$(.*?)(?=^=\s|\z)/ms';
		return preg_match( $pattern, $readme, $m ) ? trim( $m[1] ) : '';
	}

	/**
	 * GET simples no GitHub.
	 *
	 * @param string $url URL.
	 * @return string|null Corpo da resposta, ou null em erro.
	 */
	private static function get( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 10,
				'user-agent' => 'EBR-Ads-Updater/' . EBR_ADS_VERSION,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Zip que o GitHub gera para o tag.
	 *
	 * @param string $tag Nome do tag.
	 * @return string
	 */
	private static function package_url( $tag ) {
		return 'https://github.com/' . self::REPO . '/archive/refs/tags/' . rawurlencode( $tag ) . '.zip';
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
