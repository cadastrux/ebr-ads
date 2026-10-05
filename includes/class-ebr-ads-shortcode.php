<?php
/**
 * Shortcodes.
 *
 * Registra [ebr_ad id="1"] e mantém [quads_ad id="1"] / [quads id="1"] como
 * aliases, para que o conteúdo já publicado nos sites não quebre ao trocar de
 * plugin.
 *
 * §29 do documento de auditoria: todo atributo é validado. Aqui só existe um
 * atributo — `id` — e ele é um inteiro dentro de uma faixa fixa. Não há
 * atributo `class`, `style` ou `template`, justamente para que nada vindo do
 * shortcode termine em HTML.
 *
 * @package EBR_Ads
 */

defined( 'ABSPATH' ) || exit;

class EBR_Ads_Shortcode {

	/**
	 * Registra os shortcodes.
	 */
	public static function init() {
		add_shortcode( 'ebr_ad', array( __CLASS__, 'render' ) );

		// Aliases de compatibilidade. Só registramos se o QUADS não estiver
		// ativo, para não sobrescrever o plugin antigo durante uma transição.
		if ( ! shortcode_exists( 'quads_ad' ) ) {
			add_shortcode( 'quads_ad', array( __CLASS__, 'render' ) );
		}
		if ( ! shortcode_exists( 'quads' ) ) {
			add_shortcode( 'quads', array( __CLASS__, 'render' ) );
		}
	}

	/**
	 * Callback do shortcode.
	 *
	 * @param array|string $atts Atributos.
	 * @return string
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'ebr_ad' );

		/*
		 * Faixa fixa 1..10. Fora disso NADA é renderizado.
		 *
		 * Note que aqui não se pode usar clamp: `[ebr_ad id="999"]` clampado
		 * viraria `id=10` e exibiria um anúncio que o editor não pediu.
		 * validate_slot() rejeita para 0, e 0 aqui significa "não renderiza"
		 * (o shortcode é uma colocação explícita — não faz sentido sortear).
		 */
		$id = EBR_Ads_Schema::validate_slot( $atts['id'] );
		if ( $id < 1 ) {
			return '';
		}

		if ( EBR_Ads_Conditions::post_disabled() ) {
			return '';
		}

		$settings = EBR_Ads_Store::get();
		if ( ! empty( $settings['visibility']['hide_logged_in'] ) && is_user_logged_in() ) {
			return '';
		}

		// O shortcode é uma colocação explícita do editor, mas ainda respeita o
		// limite de anúncios por página — é o limite que protege a conta AdSense.
		if ( ! EBR_Ads_Conditions::can_render_more() ) {
			return '';
		}

		$html = EBR_Ads_Renderer::render( EBR_Ads_Store::get_ad_by_index( $id ), 'shortcode' );

		if ( '' !== $html ) {
			EBR_Ads_Conditions::count_render();
		}

		return $html;
	}
}
