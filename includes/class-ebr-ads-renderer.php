<?php
/**
 * Renderização do HTML do anúncio.
 *
 * Dois caminhos, com modelos de confiança diferentes:
 *
 *   type=image → anúncio ESTRUTURADO. Construímos o HTML nós mesmos a partir de
 *                campos tipados (imagem, URL, alt, target). Tudo escapado.
 *                Superfície de XSS: zero. É o caminho recomendado pela §10.
 *
 *   type=code  → HTML/JS bruto. Sai sem escape porque é isso que o AdSense exige.
 *                A defesa não está na saída — está na ESCRITA: só quem tem
 *                ebr_manage_ad_code consegue gravar este campo
 *                (ver EBR_Ads_Schema::sanitize_ad()).
 *
 * O QUADS tratava todo anúncio como o segundo caso, gravável por qualquer role
 * que passasse pelo portão único (ADS-SEC-003 + ADS-SEC-004).
 *
 * @package EBR_Ads
 */

defined( 'ABSPATH' ) || exit;

class EBR_Ads_Renderer {

	/**
	 * Renderiza um anúncio.
	 *
	 * @param array|null $ad      Anúncio já sanitizado.
	 * @param string     $context Contexto ('content', 'shortcode', 'widget'),
	 *                            exposto nos filtros.
	 * @return string HTML, ou string vazia.
	 */
	public static function render( $ad, $context = 'content' ) {
		if ( ! EBR_Ads_Store::ad_has_content( $ad ) ) {
			return '';
		}

		$inner = ( 'image' === $ad['type'] )
			? self::render_image( $ad )
			: self::render_code( $ad );

		if ( '' === $inner ) {
			return '';
		}

		$html = sprintf(
			'<div class="ebr-ad ebr-ad--%1$s" style="%2$s">%3$s</div>',
			esc_attr( $ad['align'] ),
			esc_attr( self::wrapper_style( $ad ) ),
			$inner
		);

		/**
		 * Filtra o HTML final do anúncio.
		 *
		 * @param string $html    HTML montado.
		 * @param array  $ad      Anúncio.
		 * @param string $context Contexto da renderização.
		 */
		return apply_filters( 'ebr_ads_render', $html, $ad, $context );
	}

	/**
	 * Anúncio estruturado: imagem opcionalmente envolvida por um link.
	 *
	 * @param array $ad Anúncio.
	 * @return string
	 */
	private static function render_image( array $ad ) {
		$img = wp_get_attachment_image(
			(int) $ad['image_id'],
			'full',
			false,
			array(
				'alt'     => $ad['alt'],
				'class'   => 'ebr-ad__image',
				'loading' => 'lazy',
			)
		);

		if ( ! $img ) {
			return '';
		}

		if ( '' === $ad['link_url'] ) {
			return $img;
		}

		/*
		 * rel: sempre nofollow+sponsored (é publicidade) e noopener quando
		 * target=_blank, para impedir que a página de destino acesse
		 * window.opener. Não é campo editável — não há motivo para o usuário
		 * poder relaxar isso.
		 */
		$rel = array( 'nofollow', 'sponsored' );
		if ( '_blank' === $ad['target'] ) {
			$rel[] = 'noopener';
		}

		return sprintf(
			'<a href="%1$s" target="%2$s" rel="%3$s" class="ebr-ad__link">%4$s</a>',
			esc_url( $ad['link_url'] ),
			esc_attr( $ad['target'] ),
			esc_attr( implode( ' ', $rel ) ),
			$img
		);
	}

	/**
	 * Anúncio de código bruto.
	 *
	 * A saída não é escapada — é intencional e necessário para tags de rede
	 * publicitária (<ins class="adsbygoogle">, <script>). O controle de acesso
	 * está na gravação do campo, não aqui.
	 *
	 * @param array $ad Anúncio.
	 * @return string
	 */
	private static function render_code( array $ad ) {
		/**
		 * Último ponto de intervenção antes da saída do código bruto.
		 *
		 * Um site que queira endurecer isso pode aplicar wp_kses() aqui — o que
		 * quebraria AdSense, mas é uma escolha legítima para inventário próprio.
		 *
		 * @param string $code Código do anúncio.
		 * @param array  $ad   Anúncio.
		 */
		return apply_filters( 'ebr_ads_raw_code', $ad['code'], $ad );
	}

	/**
	 * Estilo inline do wrapper.
	 *
	 * `margin` é inteiro validado (0..200) e `align` vem de allowlist, então a
	 * string resultante não pode carregar entrada arbitrária. Ainda passa por
	 * esc_attr() no chamador.
	 *
	 * @param array $ad Anúncio.
	 * @return string
	 */
	private static function wrapper_style( array $ad ) {
		$margin = (int) $ad['margin'];
		$parts  = array( sprintf( 'margin:%dpx 0', $margin ) );

		switch ( $ad['align'] ) {
			case 'center':
				$parts[] = 'text-align:center';
				break;
			case 'left':
				$parts[] = 'float:left';
				$parts[] = sprintf( 'margin-right:%dpx', $margin );
				break;
			case 'right':
				$parts[] = 'float:right';
				$parts[] = sprintf( 'margin-left:%dpx', $margin );
				break;
		}

		return implode( ';', $parts ) . ';';
	}
}
