<?php
/**
 * Schema e sanitização.
 *
 * Este arquivo é a única porta de entrada de dados do plugin. Nada é gravado em
 * wp_options sem passar por sanitize_settings().
 *
 * Princípio aplicado (§8 do documento de auditoria): onde o conjunto de valores
 * aceitáveis é definível, VALIDAMOS por allowlist e rejeitamos o resto — não
 * tentamos "limpar" a entrada. Campos livres recebem a sanitização do seu tipo.
 *
 * Contraste com o QUADS (ADS-SEC-002): lá, `update_option('quads_settings', $settings)`
 * gravava o json_decode() cru do upload, sem schema, sem validação, sem abortar
 * em erro — e o blob controlava a própria estrutura de autorização.
 *
 * @package EBR_Ads
 */

defined( 'ABSPATH' ) || exit;

class EBR_Ads_Schema {

	/** Número de slots de anúncio, espelhando o QUADS (ad1..ad10). */
	const AD_SLOTS = 10;

	/**
	 * Posições de inserção automática.
	 *
	 * As chaves são nossas; o mapeamento para as chaves do QUADS (pos1..pos9,
	 * BegnAds/BegnRnd, ...) vive só no importador, e só na leitura.
	 */
	public static function positions() {
		return array(
			'begin'     => array( 'label' => __( 'Beginning of Post', 'ebr-ads' ) ),
			'middle'    => array( 'label' => __( 'Middle of Post', 'ebr-ads' ) ),
			'end'       => array( 'label' => __( 'End of Post', 'ebr-ads' ) ),
			'more'      => array( 'label' => __( 'Right after the <!--more--> tag', 'ebr-ads' ) ),
			'last_para' => array( 'label' => __( 'Right before the last Paragraph', 'ebr-ads' ) ),
			'para1'     => array( 'label' => __( 'After Paragraph', 'ebr-ads' ), 'counted' => true ),
			'para2'     => array( 'label' => __( 'After Paragraph', 'ebr-ads' ), 'counted' => true ),
			'para3'     => array( 'label' => __( 'After Paragraph', 'ebr-ads' ), 'counted' => true ),
			'image1'    => array( 'label' => __( 'After Image', 'ebr-ads' ), 'counted' => true, 'image' => true ),
			// Depois de um elemento com esta classe CSS, em qualquer lugar da
			// página — ver EBR_Ads_Page_Injector.
			'class1'    => array( 'label' => __( 'After Class', 'ebr-ads' ), 'class' => true ),
			'class2'    => array( 'label' => __( 'After Class', 'ebr-ads' ), 'class' => true ),
		);
	}

	/**
	 * Allowlist: opções de ocultação por post (metabox da tela de edição).
	 *
	 * Espelha a caixa "WP QUADS - Hide Ads". As chaves de posição coincidem com
	 * as de positions(); 'legacy' é a chave equivalente em
	 * `_quads_config_visibility`, lida para que as marcações já feitas no QUADS
	 * continuem valendo.
	 */
	public static function post_hide_options() {
		return array(
			'all'       => array( 'label' => __( 'Ocultar todos os anúncios nesta página', 'ebr-ads' ), 'legacy' => 'NoAds' ),
			'auto'      => array( 'label' => __( 'Ocultar anúncios automáticos (manter só os inseridos manualmente por shortcode)', 'ebr-ads' ), 'legacy' => 'OffDef' ),
			'widget'    => array( 'label' => __( 'Ocultar anúncios da sidebar (widgets)', 'ebr-ads' ), 'legacy' => 'OffWidget' ),
			'begin'     => array( 'label' => __( 'Ocultar anúncio do início', 'ebr-ads' ), 'legacy' => 'OffBegin' ),
			'middle'    => array( 'label' => __( 'Ocultar anúncio do meio', 'ebr-ads' ), 'legacy' => 'OffMiddle' ),
			'end'       => array( 'label' => __( 'Ocultar anúncio do fim', 'ebr-ads' ), 'legacy' => 'OffEnd' ),
			'more'      => array( 'label' => __( 'Ocultar anúncio após a tag <!--more-->', 'ebr-ads' ), 'legacy' => 'OffAfMore' ),
			'last_para' => array( 'label' => __( 'Ocultar anúncio antes do último parágrafo', 'ebr-ads' ), 'legacy' => 'OffBfLastPara' ),
		);
	}

	/** Allowlist: tipo de anúncio. */
	public static function ad_types() {
		return array( 'code', 'image' );
	}

	/** Allowlist: alinhamento. */
	public static function alignments() {
		return array( 'none', 'left', 'center', 'right' );
	}

	/** Allowlist: target do link (anúncio estruturado). */
	public static function targets() {
		return array( '_blank', '_self' );
	}

	/** Allowlist: chaves de visibilidade. */
	public static function visibility_keys() {
		return array( 'home', 'category', 'archive', 'tag', 'hide_widget_home', 'hide_logged_in' );
	}

	/**
	 * Estrutura padrão, usada quando a option não existe e como base do merge.
	 */
	public static function defaults() {
		$ads = array();
		for ( $i = 1; $i <= self::AD_SLOTS; $i++ ) {
			$ads[ 'ad' . $i ]           = self::default_ad( 'ad-' . $i );
			$ads[ 'ad' . $i . '_widget' ] = self::default_ad( 'widget-' . $i );
		}

		$positions = array();
		foreach ( self::positions() as $key => $meta ) {
			$positions[ $key ] = array(
				'enabled'    => false,
				'ad'         => 0,
				'count'      => 1,
				'flag'       => false,
				'class_name' => '',
			);
		}

		return array(
			'ads'        => $ads,
			'positions'  => $positions,
			'max_ads'    => 3,
			'visibility' => array(
				'home'             => true,
				'category'         => true,
				'archive'          => true,
				'tag'              => true,
				'hide_widget_home' => false,
				'hide_logged_in'   => false,
			),
			'post_types' => array( 'post' ),
			'quicktags'  => true,
		);
	}

	/** Anúncio vazio. */
	public static function default_ad( $label = '' ) {
		return array(
			'label'    => $label,
			'type'     => 'code',
			'code'     => '',
			'image_id' => 0,
			'link_url' => '',
			'alt'      => '',
			'target'   => '_blank',
			'align'    => 'none',
			'margin'   => 15,
		);
	}

	/**
	 * Sanitiza a estrutura completa de settings.
	 *
	 * Recebe entrada não confiável (formulário admin ou importador) e devolve
	 * SEMPRE uma estrutura válida. Chaves desconhecidas são descartadas — a
	 * estrutura de saída é definida aqui, não pela entrada.
	 *
	 * @param mixed     $input      Entrada crua.
	 * @param array     $existing   Settings atuais, para preservar campos que o
	 *                              usuário não tem permissão de alterar.
	 * @param bool|null $allow_code Se o campo `code` pode ser gravado. null (padrão)
	 *                              = decidir pela capability do usuário atual.
	 *                              Só o importador passa true — ver o comentário
	 *                              em EBR_Ads_Importer::maybe_import().
	 * @return array
	 */
	public static function sanitize_settings( $input, $existing = null, $allow_code = null ) {
		$defaults = self::defaults();

		if ( null === $existing ) {
			$existing = get_option( EBR_ADS_OPTION, $defaults );
		}
		if ( ! is_array( $existing ) ) {
			$existing = $defaults;
		}

		// Entrada inválida não zera a configuração — devolvemos o que já existe.
		// (No QUADS, um JSON malformado virava update_option(..., null).)
		if ( ! is_array( $input ) ) {
			return $existing;
		}

		$out = array();

		// --- Anúncios -------------------------------------------------------
		$out['ads'] = array();
		foreach ( array_keys( $defaults['ads'] ) as $slot ) {
			$raw          = isset( $input['ads'][ $slot ] ) && is_array( $input['ads'][ $slot ] ) ? $input['ads'][ $slot ] : array();
			$prev         = isset( $existing['ads'][ $slot ] ) && is_array( $existing['ads'][ $slot ] ) ? $existing['ads'][ $slot ] : $defaults['ads'][ $slot ];
			$out['ads'][ $slot ] = self::sanitize_ad( $raw, $prev, $allow_code );
		}

		// --- Posições -------------------------------------------------------
		$out['positions'] = array();
		foreach ( self::positions() as $key => $meta ) {
			$raw = isset( $input['positions'][ $key ] ) && is_array( $input['positions'][ $key ] ) ? $input['positions'][ $key ] : array();

			$out['positions'][ $key ] = array(
				'enabled'    => ! empty( $raw['enabled'] ),
				'ad'         => self::validate_slot( isset( $raw['ad'] ) ? $raw['ad'] : 0 ),
				'count'      => self::clamp_int( isset( $raw['count'] ) ? $raw['count'] : 1, 1, 100, 1 ),
				'flag'       => ! empty( $raw['flag'] ),
				'class_name' => empty( $meta['class'] ) ? '' : self::validate_css_class( isset( $raw['class_name'] ) ? $raw['class_name'] : '' ),
			);
		}

		// --- Limite de anúncios por página ----------------------------------
		// 0 = ilimitado.
		$out['max_ads'] = self::clamp_int( isset( $input['max_ads'] ) ? $input['max_ads'] : 3, 0, 100, 3 );

		// --- Visibilidade ---------------------------------------------------
		$out['visibility'] = array();
		foreach ( self::visibility_keys() as $key ) {
			$out['visibility'][ $key ] = ! empty( $input['visibility'][ $key ] );
		}

		// --- Post types -----------------------------------------------------
		// Allowlist derivada do que está REGISTRADO no site, não do que veio no
		// request. Um post type inexistente é descartado em silêncio.
		$out['post_types'] = array();
		$allowed_types     = self::allowed_post_types();
		if ( isset( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			foreach ( $input['post_types'] as $type ) {
				$type = sanitize_key( $type );
				if ( in_array( $type, $allowed_types, true ) ) {
					$out['post_types'][] = $type;
				}
			}
		}
		$out['post_types'] = array_values( array_unique( $out['post_types'] ) );

		$out['quicktags'] = ! empty( $input['quicktags'] );

		return $out;
	}

	/**
	 * Sanitiza um anúncio.
	 *
	 * @param array     $raw        Entrada crua.
	 * @param array     $prev       Valor anterior, preservado para campos privilegiados.
	 * @param bool|null $allow_code Ver sanitize_settings().
	 * @return array
	 */
	public static function sanitize_ad( $raw, $prev, $allow_code = null ) {
		$ad = self::default_ad();

		$ad['label'] = isset( $raw['label'] )
			? sanitize_text_field( wp_unslash( $raw['label'] ) )
			: ( isset( $prev['label'] ) ? $prev['label'] : '' );

		// Allowlist estrita, comparação com terceiro parâmetro true (§8).
		$ad['type'] = ( isset( $raw['type'] ) && in_array( $raw['type'], self::ad_types(), true ) )
			? $raw['type']
			: 'code';

		/*
		 * ADS-SEC-003 — o controle central deste plugin.
		 *
		 * `code` é HTML/JavaScript executado em todas as páginas do site. Só é
		 * gravável por quem tem ebr_manage_ad_code. Sem essa capability o campo
		 * é PRESERVADO, não apagado: um editor pode ajustar rótulo, margem e
		 * alinhamento sem tocar no código, e sem destruí-lo por omissão.
		 *
		 * O QUADS gravava este campo (e mais nove) sem sanitização alguma, para
		 * qualquer role que passasse pelo portão único.
		 */
		$code_allowed = ( null === $allow_code ) ? EBR_Ads_Caps::can_manage_code() : (bool) $allow_code;

		if ( $code_allowed && isset( $raw['code'] ) ) {
			$ad['code'] = trim( wp_unslash( $raw['code'] ) );
		} else {
			$ad['code'] = isset( $prev['code'] ) ? $prev['code'] : '';
		}

		// Anúncio estruturado: sem superfície de XSS, tudo escapado na saída.
		$ad['image_id'] = self::clamp_int( isset( $raw['image_id'] ) ? $raw['image_id'] : 0, 0, PHP_INT_MAX, 0 );

		$ad['link_url'] = '';
		if ( ! empty( $raw['link_url'] ) ) {
			// esc_url_raw com protocolos explícitos: bloqueia javascript:, data:, vbscript:.
			$url = esc_url_raw( wp_unslash( $raw['link_url'] ), array( 'http', 'https' ) );
			if ( $url ) {
				$ad['link_url'] = $url;
			}
		}

		$ad['alt'] = isset( $raw['alt'] ) ? sanitize_text_field( wp_unslash( $raw['alt'] ) ) : '';

		$ad['target'] = ( isset( $raw['target'] ) && in_array( $raw['target'], self::targets(), true ) )
			? $raw['target']
			: '_blank';

		$ad['align'] = ( isset( $raw['align'] ) && in_array( $raw['align'], self::alignments(), true ) )
			? $raw['align']
			: 'none';

		$ad['margin'] = self::clamp_int( isset( $raw['margin'] ) ? $raw['margin'] : 15, 0, 200, 15 );

		return $ad;
	}

	/**
	 * Post types onde a inserção automática pode ocorrer.
	 */
	public static function allowed_post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * Valida uma referência a slot de anúncio.
	 *
	 * 0 = "Random Ads"; 1..AD_SLOTS = ad1..adN.
	 *
	 * Aqui NÃO usamos clamp: um valor fora da faixa é entrada inválida, e
	 * clampar transformaria `ad=99` em `ad=10` — atribuindo silenciosamente um
	 * anúncio que o usuário nunca escolheu. Rejeitamos para 0 (Random), que é
	 * o padrão documentado. §8: validar e rejeitar, não "consertar".
	 *
	 * @param mixed $value Valor cru.
	 * @return int
	 */
	public static function validate_slot( $value ) {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return 0;
		}

		$slot = (int) $value;

		return ( $slot >= 0 && $slot <= self::AD_SLOTS ) ? $slot : 0;
	}

	/**
	 * Valida um nome de classe CSS (uma só, sem o ponto).
	 *
	 * O valor vira parte de uma expressão regular e é comparado com o HTML da
	 * página, então aceitamos apenas a gramática de identificador CSS — letras,
	 * dígitos, '-' e '_'. Um ponto inicial ('.cat-content') é tolerado, porque é
	 * como se escreve a classe num seletor. Qualquer outra coisa vira '' (a
	 * posição simplesmente não insere nada). §8: validar, não "consertar".
	 *
	 * @param mixed $value Valor cru.
	 * @return string
	 */
	public static function validate_css_class( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = ltrim( trim( (string) $value ), '.' );

		if ( strlen( $value ) > 100 || ! preg_match( '/^-?[_a-zA-Z][_a-zA-Z0-9-]*$/', $value ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Converte para inteiro dentro de uma faixa, com fallback.
	 *
	 * @param mixed $value    Valor cru.
	 * @param int   $min      Mínimo.
	 * @param int   $max      Máximo.
	 * @param int   $fallback Usado quando o valor não é numérico.
	 * @return int
	 */
	public static function clamp_int( $value, $min, $max, $fallback ) {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return $fallback;
		}
		return (int) max( $min, min( $max, (int) $value ) );
	}
}
