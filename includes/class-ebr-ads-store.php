<?php
/**
 * Acesso aos settings.
 *
 * Camada única de leitura/escrita. Não existe segundo caminho de dados —
 * o QUADS mantinha wp_options E um CPT sincronizados por um migration-service
 * bidirecional (ADS-SEC-023), o que fez cada correção precisar ser aplicada
 * duas vezes e originou o endpoint do CVE.
 *
 * @package EBR_Ads
 */

defined( 'ABSPATH' ) || exit;

class EBR_Ads_Store {

	/** Cache por request. */
	private static $cache = null;

	/**
	 * Settings completos, já normalizados contra os defaults.
	 *
	 * @return array
	 */
	public static function get() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored   = get_option( EBR_ADS_OPTION, array() );
		$defaults = EBR_Ads_Schema::defaults();

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		// Merge raso por seção: garante que uma option antiga ou parcial nunca
		// produza notices de índice indefinido no frontend.
		$settings = $defaults;
		foreach ( array( 'ads', 'positions', 'visibility' ) as $section ) {
			if ( isset( $stored[ $section ] ) && is_array( $stored[ $section ] ) ) {
				foreach ( $stored[ $section ] as $key => $value ) {
					if ( isset( $defaults[ $section ][ $key ] ) && is_array( $value ) && is_array( $defaults[ $section ][ $key ] ) ) {
						$settings[ $section ][ $key ] = array_merge( $defaults[ $section ][ $key ], $value );
					} elseif ( isset( $defaults[ $section ][ $key ] ) ) {
						$settings[ $section ][ $key ] = $value;
					}
				}
			}
		}
		foreach ( array( 'max_ads', 'post_types', 'quicktags' ) as $key ) {
			if ( isset( $stored[ $key ] ) ) {
				$settings[ $key ] = $stored[ $key ];
			}
		}

		self::$cache = $settings;
		return self::$cache;
	}

	/**
	 * Grava settings já sanitizados.
	 *
	 * @param array $settings Estrutura vinda de EBR_Ads_Schema::sanitize_settings().
	 * @return bool
	 */
	public static function save( array $settings ) {
		self::$cache = null;

		$changed = update_option( EBR_ADS_OPTION, $settings );
		if ( $changed ) {
			self::purge_page_cache();
		}

		return $changed;
	}

	/**
	 * Limpa o cache de página depois de uma mudança nos settings.
	 *
	 * Anúncios fazem parte do HTML da página. Plugins de cache limpam uma página
	 * quando o POST é salvo, mas não sabem que a nossa option mudou — sem isto,
	 * desligar anúncios na home (ou trocar um código) só aparece quando o cache
	 * expira, e parece que a configuração "não funcionou".
	 *
	 * Só chama as APIs públicas de cada plugin, e só se ele estiver ativo.
	 */
	private static function purge_page_cache() {
		// LiteSpeed Cache.
		do_action( 'litespeed_purge_all' );

		// WP Rocket.
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		// WP Super Cache.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		/**
		 * Disparado depois que os settings mudam, para outros caches (CDN,
		 * proxy reverso) se ligarem.
		 */
		do_action( 'ebr_ads_settings_saved' );
	}

	/**
	 * Um anúncio pelo slot ('ad1', 'ad3_widget', ...).
	 *
	 * @param string $slot Slot.
	 * @return array|null
	 */
	public static function get_ad( $slot ) {
		$settings = self::get();
		return isset( $settings['ads'][ $slot ] ) ? $settings['ads'][ $slot ] : null;
	}

	/**
	 * Um anúncio de conteúdo pelo índice numérico (1..10).
	 *
	 * @param int $index Índice.
	 * @return array|null
	 */
	public static function get_ad_by_index( $index ) {
		$index = (int) $index;
		if ( $index < 1 || $index > EBR_Ads_Schema::AD_SLOTS ) {
			return null;
		}
		return self::get_ad( 'ad' . $index );
	}

	/**
	 * O anúncio tem conteúdo utilizável?
	 *
	 * @param array|null $ad Anúncio.
	 * @return bool
	 */
	public static function ad_has_content( $ad ) {
		if ( ! is_array( $ad ) ) {
			return false;
		}
		if ( 'image' === $ad['type'] ) {
			return ! empty( $ad['image_id'] );
		}
		return '' !== trim( (string) $ad['code'] );
	}

	/**
	 * Resolve o slot configurado numa posição para um anúncio concreto.
	 *
	 * Slot 0 significa "Random Ads": sorteia entre os anúncios de conteúdo que
	 * têm conteúdo de fato.
	 *
	 * @param int $slot Slot configurado (0..10).
	 * @return array|null
	 */
	public static function resolve_ad( $slot ) {
		$slot = (int) $slot;

		if ( $slot > 0 ) {
			$ad = self::get_ad_by_index( $slot );
			return self::ad_has_content( $ad ) ? $ad : null;
		}

		$pool = array();
		for ( $i = 1; $i <= EBR_Ads_Schema::AD_SLOTS; $i++ ) {
			$ad = self::get_ad_by_index( $i );
			if ( self::ad_has_content( $ad ) ) {
				$pool[] = $ad;
			}
		}

		if ( empty( $pool ) ) {
			return null;
		}

		return $pool[ wp_rand( 0, count( $pool ) - 1 ) ];
	}
}
