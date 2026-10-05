<?php
/**
 * Importação única a partir do Quick AdSense Reloaded (QUADS).
 *
 * Estratégia escolhida: LER UMA VEZ, na ativação, e normalizar para o formato
 * próprio. Nunca escrevemos de volta em `quads_settings`, nunca lemos o formato
 * antigo em tempo de execução.
 *
 * Foi a compatibilidade viva bidirecional do QUADS que produziu o
 * migration-service, a duplicação common.php/commonV2.php e o endpoint
 * `import_old_db` sem autorização que originou o CVE-2026-42732.
 *
 * Fontes lidas:
 *   1. wp_options['quads_settings']  — painel clássico (ad1..ad10, pos1..pos9)
 *   2. CPT 'quads-ads' + post meta   — painel novo
 *
 * @package EBR_Ads
 */

defined( 'ABSPATH' ) || exit;

class EBR_Ads_Importer {

	/** Flag que marca a importação como já executada. */
	const DONE_FLAG = 'ebr_ads_import_done';

	/**
	 * Mapa posição nossa → chaves do QUADS.
	 *
	 * ['pos', 'chave do checkbox', 'chave do select', 'chave do contador', 'chave do flag']
	 */
	private static function position_map() {
		return array(
			'begin'     => array( 'pos1', 'BegnAds', 'BegnRnd', null, null ),
			'middle'    => array( 'pos2', 'MiddAds', 'MiddRnd', null, null ),
			'end'       => array( 'pos3', 'EndiAds', 'EndiRnd', null, null ),
			'more'      => array( 'pos4', 'MoreAds', 'MoreRnd', null, null ),
			'last_para' => array( 'pos5', 'LapaAds', 'LapaRnd', null, null ),
			'para1'     => array( 'pos6', 'Par1Ads', 'Par1Rnd', 'Par1Nup', 'Par1Con' ),
			'para2'     => array( 'pos7', 'Par2Ads', 'Par2Rnd', 'Par2Nup', 'Par2Con' ),
			'para3'     => array( 'pos8', 'Par3Ads', 'Par3Rnd', 'Par3Nup', 'Par3Con' ),
			'image1'    => array( 'pos9', 'Img1Ads', 'Img1Rnd', 'Img1Nup', 'Img1Con' ),
		);
	}

	/** Mapa visibilidade nossa → chaves do QUADS. */
	private static function visibility_map() {
		return array(
			'home'             => 'AppHome',
			'category'         => 'AppCate',
			'archive'          => 'AppArch',
			'tag'              => 'AppTags',
			'hide_widget_home' => 'AppSide',
			'hide_logged_in'   => 'AppLogg',
		);
	}

	/**
	 * Importa, se ainda não foi feito e se houver dados do QUADS.
	 *
	 * @param bool $force Reexecutar mesmo já tendo rodado.
	 * @return array Resumo do que foi importado.
	 */
	public static function maybe_import( $force = false ) {
		$summary = array(
			'ran'       => false,
			'ads'       => 0,
			'positions' => 0,
			'source'    => array(),
		);

		if ( ! $force && get_option( self::DONE_FLAG ) ) {
			return $summary;
		}

		$quads = get_option( 'quads_settings' );
		$cpt   = self::read_cpt_ads();

		if ( ( ! is_array( $quads ) || empty( $quads ) ) && empty( $cpt ) ) {
			// Nada para importar — instalação limpa.
			update_option( self::DONE_FLAG, time() );
			return $summary;
		}

		if ( ! is_array( $quads ) ) {
			$quads = array();
		}

		$draft = EBR_Ads_Schema::defaults();

		// --- Anúncios do painel clássico ------------------------------------
		if ( isset( $quads['ads'] ) && is_array( $quads['ads'] ) ) {
			$summary['source'][] = 'quads_settings';
			foreach ( $quads['ads'] as $slot => $old ) {
				$slot = sanitize_key( $slot );
				if ( ! isset( $draft['ads'][ $slot ] ) || ! is_array( $old ) ) {
					continue;
				}
				$draft['ads'][ $slot ] = self::map_ad( $old, $draft['ads'][ $slot ] );
				if ( '' !== trim( (string) $draft['ads'][ $slot ]['code'] ) ) {
					$summary['ads']++;
				}
				self::map_after_class( $old, $slot, $draft, $summary );
			}
		}

		// --- Anúncios do painel novo (CPT) ----------------------------------
		// Só ocupam slots que continuam vazios: o painel clássico tem prioridade,
		// porque é dele que vêm os dados que os seus sites usam hoje.
		if ( ! empty( $cpt ) ) {
			$summary['source'][] = 'quads-ads (CPT)';
			foreach ( $cpt as $ad ) {
				$free = self::first_empty_slot( $draft['ads'] );
				if ( null === $free ) {
					break;
				}
				$draft['ads'][ $free ] = self::map_ad( $ad, $draft['ads'][ $free ] );
				$summary['ads']++;
				self::map_after_class( $ad, $free, $draft, $summary );
			}
		}

		// --- Posições -------------------------------------------------------
		foreach ( self::position_map() as $key => $map ) {
			list( $pos, $check, $select, $count, $flag ) = $map;

			$old = isset( $quads[ $pos ] ) && is_array( $quads[ $pos ] ) ? $quads[ $pos ] : array();
			if ( empty( $old ) ) {
				continue;
			}

			$enabled = ! empty( $old[ $check ] );

			$draft['positions'][ $key ] = array(
				'enabled' => $enabled,
				'ad'      => isset( $old[ $select ] ) ? (int) $old[ $select ] : 0,
				'count'   => ( $count && isset( $old[ $count ] ) ) ? (int) $old[ $count ] : 1,
				'flag'    => ( $flag && ! empty( $old[ $flag ] ) ),
			);

			if ( $enabled ) {
				$summary['positions']++;
			}
		}

		// --- Limite, visibilidade, post types -------------------------------
		if ( isset( $quads['maxads'] ) ) {
			$draft['max_ads'] = (int) $quads['maxads'];
		} elseif ( isset( $quads['visibility']['AppMaxA'] ) ) {
			$draft['max_ads'] = (int) $quads['visibility']['AppMaxA'];
		}

		foreach ( self::visibility_map() as $ours => $theirs ) {
			if ( isset( $quads['visibility'][ $theirs ] ) ) {
				$draft['visibility'][ $ours ] = ! empty( $quads['visibility'][ $theirs ] );
			}
		}

		if ( isset( $quads['post_types'] ) && is_array( $quads['post_types'] ) ) {
			$draft['post_types'] = $quads['post_types'];
		}

		if ( isset( $quads['quicktags'] ) ) {
			$draft['quicktags'] = is_array( $quads['quicktags'] )
				? ! empty( $quads['quicktags']['QckTags'] )
				: ! empty( $quads['quicktags'] );
		}

		/*
		 * $allow_code = true.
		 *
		 * Justificativa: a origem não é input de request, é a configuração que já
		 * está no banco deste site, gravada por um administrador através do QUADS.
		 * Além disso, maybe_import() só roda em register_activation_hook, que
		 * exige a capability 'activate_plugins'.
		 *
		 * Passamos explicitamente em vez de depender de current_user_can() porque
		 * as capabilities acabaram de ser criadas por EBR_Ads_Caps::add_caps() e o
		 * objeto WP_User do request atual pode ainda ter as caps antigas em cache.
		 */
		$clean = EBR_Ads_Schema::sanitize_settings( $draft, EBR_Ads_Schema::defaults(), true );

		EBR_Ads_Store::save( $clean );
		update_option( self::DONE_FLAG, time() );

		$summary['ran'] = true;
		return $summary;
	}

	/**
	 * Normaliza um anúncio do QUADS (clássico ou CPT) para o nosso formato.
	 *
	 * A sanitização real acontece depois, em sanitize_settings(). Aqui só
	 * traduzimos nomes de campo.
	 *
	 * @param array $old  Anúncio no formato QUADS.
	 * @param array $base Anúncio base do nosso schema.
	 * @return array
	 */
	private static function map_ad( array $old, array $base ) {
		$ad = $base;

		if ( isset( $old['label'] ) && '' !== trim( (string) $old['label'] ) ) {
			$ad['label'] = $old['label'];
		}

		// O QUADS guarda o código bruto em 'code'. O tipo dele ('plain_text',
		// 'adsense', 'ad_code'...) não muda o armazenamento: é sempre HTML.
		if ( isset( $old['code'] ) ) {
			$ad['code'] = $old['code'];
		}

		$ad['type'] = 'code';

		// Alinhamento: QUADS usa 0=none/left, 1=center, 2=right.
		if ( isset( $old['align'] ) ) {
			$map          = array( 0 => 'none', 1 => 'center', 2 => 'right' );
			$key          = (int) $old['align'];
			$ad['align']  = isset( $map[ $key ] ) ? $map[ $key ] : 'none';
		}

		if ( isset( $old['margin'] ) && is_numeric( $old['margin'] ) ) {
			$ad['margin'] = (int) $old['margin'];
		}

		return $ad;
	}

	/**
	 * Anúncio do painel novo do QUADS com posição "After Class" vira a nossa
	 * posição class1, apontando para o slot onde o anúncio foi importado.
	 *
	 * Só existe uma posição class1; o primeiro anúncio "After Class" encontrado
	 * fica com ela. O QUADS inseria depois de TODAS as ocorrências da classe,
	 * então o flag "todas" vem ligado para manter o comportamento.
	 *
	 * @param array  $old      Anúncio no formato QUADS.
	 * @param string $slot     Slot de destino ('ad5').
	 * @param array  $draft    Settings em construção (por referência).
	 * @param array  $summary  Resumo da importação (por referência).
	 */
	private static function map_after_class( array $old, $slot, array &$draft, array &$summary ) {
		if ( ! isset( $old['position'] ) || 'ad_after_class' !== $old['position'] ) {
			return;
		}
		if ( ! empty( $draft['positions']['class1']['enabled'] ) ) {
			return;
		}
		if ( ! preg_match( '/^ad(\d+)$/', $slot, $m ) ) {
			return;
		}

		// Validado de novo em sanitize_settings(); aqui só evitamos ligar a
		// posição com uma classe que seria descartada.
		$class = EBR_Ads_Schema::validate_css_class( isset( $old['after_class_name'] ) ? $old['after_class_name'] : '' );
		if ( '' === $class ) {
			return;
		}

		$draft['positions']['class1'] = array(
			'enabled'    => true,
			'ad'         => (int) $m[1],
			'count'      => 1,
			'flag'       => true,
			'class_name' => $class,
		);
		$summary['positions']++;
	}

	/**
	 * Lê os anúncios do CPT 'quads-ads', se existir.
	 *
	 * @return array Lista no formato do map_ad().
	 */
	private static function read_cpt_ads() {
		$out = array();

		// get_posts com post_type inexistente devolve array vazio — sem erro.
		$posts = get_posts(
			array(
				'post_type'        => 'quads-ads',
				'post_status'      => array( 'publish', 'draft' ),
				'numberposts'      => EBR_Ads_Schema::AD_SLOTS,
				'suppress_filters' => true,
			)
		);

		foreach ( $posts as $post ) {
			$code = get_post_meta( $post->ID, 'code', true );
			if ( '' === trim( (string) $code ) ) {
				continue;
			}
			$out[] = array(
				'label'            => $post->post_title,
				'code'             => $code,
				'align'            => get_post_meta( $post->ID, 'align', true ),
				'margin'           => get_post_meta( $post->ID, 'margin', true ),
				// Rascunho no QUADS não era exibido; não ligamos posição para ele.
				'position'         => 'publish' === $post->post_status ? get_post_meta( $post->ID, 'position', true ) : '',
				'after_class_name' => get_post_meta( $post->ID, 'after_class_name', true ),
			);
		}

		return $out;
	}

	/**
	 * Primeiro slot de conteúdo (não-widget) ainda sem código.
	 *
	 * @param array $ads Anúncios.
	 * @return string|null
	 */
	private static function first_empty_slot( array $ads ) {
		for ( $i = 1; $i <= EBR_Ads_Schema::AD_SLOTS; $i++ ) {
			$slot = 'ad' . $i;
			if ( isset( $ads[ $slot ] ) && '' === trim( (string) $ads[ $slot ]['code'] ) ) {
				return $slot;
			}
		}
		return null;
	}
}
