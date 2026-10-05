<?php
/**
 * Condições de exibição.
 *
 * Decide se a inserção automática deve rodar no request atual, e mantém o
 * contador de anúncios já inseridos na página (limite max_ads).
 *
 * @package EBR_Ads
 */

defined( 'ABSPATH' ) || exit;

class EBR_Ads_Conditions {

	/** Anúncios já renderizados neste request. */
	private static $rendered = 0;

	/**
	 * A inserção automática deve ocorrer neste request?
	 *
	 * @param int|null $post_id Post cujas marcações valem. null = post do loop
	 *                          atual (inserção no conteúdo); 0 = nenhum (decisão
	 *                          da página inteira fora de páginas singulares).
	 * @return bool
	 */
	public static function should_display( $post_id = null ) {
		$settings = EBR_Ads_Store::get();

		// Nunca no admin, em feeds, em REST ou em requests AJAX.
		if ( is_admin() || is_feed() || wp_doing_ajax() ) {
			return false;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( ! empty( $settings['visibility']['hide_logged_in'] ) && is_user_logged_in() ) {
			return false;
		}

		if ( self::post_disabled( $post_id ) ) {
			return false;
		}

		if ( self::is_homepage() ) {
			return ! empty( $settings['visibility']['home'] );
		}
		if ( is_category() ) {
			return ! empty( $settings['visibility']['category'] );
		}
		if ( is_tag() ) {
			return ! empty( $settings['visibility']['tag'] );
		}
		if ( is_archive() ) {
			return ! empty( $settings['visibility']['archive'] );
		}

		if ( is_singular() ) {
			$post_type = $post_id ? get_post_type( $post_id ) : get_post_type();
			return in_array( $post_type, (array) $settings['post_types'], true );
		}

		return false;
	}

	/**
	 * O post atual está marcado para não exibir anúncios?
	 *
	 * @param int|null $post_id Post a verificar. null = post do loop atual.
	 * @return bool
	 */
	public static function post_disabled( $post_id = null ) {
		return self::post_hides( 'all', $post_id );
	}

	/**
	 * O post marcou a opção de ocultação $key no metabox?
	 *
	 * Lê tanto a nossa meta quanto a do QUADS (`_quads_config_visibility`), para
	 * que os posts que os seus sites já marcaram continuem como estavam depois
	 * da troca de plugin.
	 *
	 * 'all' fica em `_ebr_ads_disabled` (a meta original do plugin, para não
	 * perder marcações já gravadas); as demais em `_ebr_ads_hide`, uma lista de
	 * chaves de EBR_Ads_Schema::post_hide_options().
	 *
	 * @param string   $key     Chave de post_hide_options().
	 * @param int|null $post_id Post a verificar. null = post do loop atual.
	 * @return bool
	 */
	public static function post_hides( $key, $post_id = null ) {
		$options = EBR_Ads_Schema::post_hide_options();
		if ( ! isset( $options[ $key ] ) ) {
			return false;
		}

		$post_id = ( null === $post_id ) ? get_the_ID() : (int) $post_id;
		if ( ! $post_id ) {
			return false;
		}

		if ( 'all' === $key ) {
			if ( get_post_meta( $post_id, '_ebr_ads_disabled', true ) ) {
				return true;
			}
		} else {
			$hide = get_post_meta( $post_id, '_ebr_ads_hide', true );
			if ( is_array( $hide ) && in_array( $key, $hide, true ) ) {
				return true;
			}
		}

		$legacy = get_post_meta( $post_id, '_quads_config_visibility', true );
		return is_array( $legacy ) && ! empty( $legacy[ $options[ $key ]['legacy'] ] );
	}

	/**
	 * Os widgets de anúncio devem aparecer neste request?
	 *
	 * @return bool
	 */
	public static function should_display_widget() {
		$settings = EBR_Ads_Store::get();

		if ( ! empty( $settings['visibility']['hide_logged_in'] ) && is_user_logged_in() ) {
			return false;
		}
		if ( ! empty( $settings['visibility']['hide_widget_home'] ) && self::is_homepage() ) {
			return false;
		}

		return true;
	}

	/**
	 * O request é a página inicial (blog ou página estática definida como home)?
	 *
	 * Consulta também $wp_the_query — a query ORIGINAL do request. Templates de
	 * home costumam chamar query_posts(), que substitui $wp_query; depois disso
	 * is_front_page() responde sobre a query nova e a home deixa de ser
	 * reconhecida, ignorando a opção "Homepage".
	 *
	 * @return bool
	 */
	public static function is_homepage() {
		if ( is_home() || is_front_page() ) {
			return true;
		}

		$main = isset( $GLOBALS['wp_the_query'] ) ? $GLOBALS['wp_the_query'] : null;

		return $main instanceof WP_Query && ( $main->is_home() || $main->is_front_page() );
	}

	/**
	 * Ainda cabe mais um anúncio na página?
	 *
	 * @return bool
	 */
	public static function can_render_more() {
		$settings = EBR_Ads_Store::get();
		$max      = (int) $settings['max_ads'];

		if ( $max <= 0 ) {
			return true; // 0 = ilimitado.
		}

		return self::$rendered < $max;
	}

	/**
	 * Contabiliza um anúncio renderizado.
	 */
	public static function count_render() {
		self::$rendered++;
	}

	/**
	 * Quantos já foram renderizados neste request.
	 *
	 * @return int
	 */
	public static function rendered_count() {
		return self::$rendered;
	}

	/**
	 * Zera o contador. Usado nos testes e em loops que reprocessam o conteúdo.
	 */
	public static function reset() {
		self::$rendered = 0;
	}
}
