<?php
/**
 * Inserção automática no conteúdo.
 *
 * Reproduz as nove posições do painel clássico do QUADS:
 *   início, meio, fim, após <!--more-->, antes do último parágrafo,
 *   após o parágrafo N (três slots) e após a imagem N.
 *
 * @package EBR_Ads
 */

defined( 'ABSPATH' ) || exit;

class EBR_Ads_Injector {

	/**
	 * Registra os hooks.
	 */
	public static function init() {
		// Prioridade 10: depois do wpautop (que roda em 10 também, mas foi
		// adicionado antes por core), de forma que o conteúdo já tem <p>.
		add_filter( 'the_content', array( __CLASS__, 'inject' ), 20 );
		add_action( 'wp_head', array( __CLASS__, 'print_styles' ) );
	}

	/**
	 * CSS mínimo do wrapper.
	 */
	public static function print_styles() {
		echo '<style id="ebr-ads-inline">.ebr-ad{clear:both}.ebr-ad--left,.ebr-ad--right{clear:none}.ebr-ad__image{max-width:100%;height:auto}</style>' . "\n";
	}

	/**
	 * Filtro de the_content.
	 *
	 * @param string $content Conteúdo do post.
	 * @return string
	 */
	public static function inject( $content ) {
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return $content;
		}

		// Só o loop principal. Evita injetar em widgets de "posts relacionados",
		// blocos de query e chamadas programáticas a the_content().
		if ( ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		// Resumo de listagem: wp_trim_excerpt() passa o conteúdo inteiro por
		// the_content e depois remove as tags. O anúncio inserido aqui some do
		// resumo, mas contaria no limite por página — numa categoria com nove
		// posts, o limite se esgotava em anúncios invisíveis.
		if ( doing_filter( 'get_the_excerpt' ) ) {
			return $content;
		}

		if ( ! EBR_Ads_Conditions::should_display() ) {
			return $content;
		}

		$settings = EBR_Ads_Store::get();

		// Post type precisa estar habilitado, inclusive em listagens.
		if ( ! in_array( get_post_type(), (array) $settings['post_types'], true ) ) {
			return $content;
		}

		// "Ocultar anúncios automáticos": shortcodes continuam funcionando.
		if ( EBR_Ads_Conditions::post_hides( 'auto' ) ) {
			return $content;
		}

		$positions = $settings['positions'];

		// Posições ocultadas individualmente no metabox do post.
		foreach ( array_keys( $positions ) as $key ) {
			if ( EBR_Ads_Conditions::post_hides( $key ) ) {
				$positions[ $key ]['enabled'] = false;
			}
		}

		// Imagem primeiro: trabalha no HTML bruto, antes de fatiar por parágrafo.
		if ( ! empty( $positions['image1']['enabled'] ) ) {
			$content = self::insert_after_image( $content, $positions['image1'] );
		}

		// <!--more--> vira <span id="more-123"></span> depois do the_content.
		if ( ! empty( $positions['more']['enabled'] ) ) {
			$content = self::insert_after_more( $content, $positions['more'] );
		}

		return self::insert_by_paragraph( $content, $positions );
	}

	/**
	 * Insere as posições baseadas em contagem de parágrafo.
	 *
	 * Coleta todas as inserções em um mapa índice => HTML e reconstrói o
	 * conteúdo em uma única passada, para que um insert não desloque o índice
	 * do seguinte.
	 *
	 * @param string $content   Conteúdo.
	 * @param array  $positions Configuração de posições.
	 * @return string
	 */
	private static function insert_by_paragraph( $content, array $positions ) {
		$chunks = self::split_paragraphs( $content );
		$total  = count( $chunks );

		if ( 0 === $total ) {
			return $content;
		}

		$plan = array();

		// Ordem de resolução = ordem visual, para que o limite max_ads corte
		// os de baixo, não os de cima.
		$ordered = array(
			'begin'     => 0,
			'para1'     => null,
			'para2'     => null,
			'para3'     => null,
			'middle'    => (int) floor( $total / 2 ),
			'last_para' => max( 0, $total - 1 ),
			'end'       => $total,
		);

		foreach ( $ordered as $key => $index ) {
			$config = isset( $positions[ $key ] ) ? $positions[ $key ] : null;
			if ( empty( $config['enabled'] ) ) {
				continue;
			}

			if ( null === $index ) {
				// Slots "após o parágrafo N".
				$n = (int) $config['count'];
				if ( $n > $total ) {
					// 'flag' = "to End of Post if fewer paragraphs are found".
					if ( empty( $config['flag'] ) ) {
						continue;
					}
					$n = $total;
				}
				$index = $n;
			}

			if ( ! EBR_Ads_Conditions::can_render_more() ) {
				break;
			}

			$html = EBR_Ads_Renderer::render( EBR_Ads_Store::resolve_ad( $config['ad'] ), 'content' );
			if ( '' === $html ) {
				continue;
			}

			EBR_Ads_Conditions::count_render();

			$index = max( 0, min( $total, (int) $index ) );
			if ( ! isset( $plan[ $index ] ) ) {
				$plan[ $index ] = '';
			}
			$plan[ $index ] .= $html;
		}

		if ( empty( $plan ) ) {
			return $content;
		}

		$out = '';
		for ( $i = 0; $i < $total; $i++ ) {
			if ( isset( $plan[ $i ] ) ) {
				$out .= $plan[ $i ];
			}
			$out .= $chunks[ $i ];
		}
		if ( isset( $plan[ $total ] ) ) {
			$out .= $plan[ $total ];
		}

		return $out;
	}

	/**
	 * Quebra o conteúdo em blocos de parágrafo.
	 *
	 * Cada bloco termina em </p>, exceto possivelmente o último (resto que não
	 * está dentro de parágrafo — shortcodes, blocos, HTML solto).
	 *
	 * @param string $content Conteúdo.
	 * @return string[]
	 */
	private static function split_paragraphs( $content ) {
		$parts = preg_split( '#</p>#i', $content );

		if ( false === $parts ) {
			return array( $content );
		}

		$chunks = array();
		$last   = count( $parts ) - 1;

		foreach ( $parts as $i => $part ) {
			if ( $i < $last ) {
				$chunks[] = $part . '</p>';
			} elseif ( '' !== trim( $part ) ) {
				$chunks[] = $part;
			}
		}

		return $chunks;
	}

	/**
	 * Insere depois da tag <!--more-->.
	 *
	 * @param string $content Conteúdo.
	 * @param array  $config  Configuração da posição.
	 * @return string
	 */
	private static function insert_after_more( $content, array $config ) {
		if ( ! EBR_Ads_Conditions::can_render_more() ) {
			return $content;
		}

		// O core substitui <!--more--> por esta âncora ao renderizar.
		if ( ! preg_match( '#<span id="more-\d+"></span>#i', $content, $match, PREG_OFFSET_CAPTURE ) ) {
			return $content;
		}

		$html = EBR_Ads_Renderer::render( EBR_Ads_Store::resolve_ad( $config['ad'] ), 'content' );
		if ( '' === $html ) {
			return $content;
		}

		EBR_Ads_Conditions::count_render();

		$at = (int) $match[0][1] + strlen( $match[0][0] );

		return substr( $content, 0, $at ) . $html . substr( $content, $at );
	}

	/**
	 * Insere depois da N-ésima imagem.
	 *
	 * Com 'flag' ativo, insere depois do <div class="wp-caption"> que envolve a
	 * imagem, quando existir — replicando a opção "after Image's outer <div>
	 * wp-caption if any" do painel clássico.
	 *
	 * @param string $content Conteúdo.
	 * @param array  $config  Configuração da posição.
	 * @return string
	 */
	private static function insert_after_image( $content, array $config ) {
		if ( ! EBR_Ads_Conditions::can_render_more() ) {
			return $content;
		}

		if ( ! preg_match_all( '#<img[^>]*>#i', $content, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $content;
		}

		$n = (int) $config['count'];
		if ( $n < 1 || $n > count( $matches[0] ) ) {
			return $content;
		}

		$match = $matches[0][ $n - 1 ];
		$at    = (int) $match[1] + strlen( $match[0] );

		if ( ! empty( $config['flag'] ) ) {
			$at = self::end_of_caption( $content, (int) $match[1], $at );
		}

		$html = EBR_Ads_Renderer::render( EBR_Ads_Store::resolve_ad( $config['ad'] ), 'content' );
		if ( '' === $html ) {
			return $content;
		}

		EBR_Ads_Conditions::count_render();

		return substr( $content, 0, $at ) . $html . substr( $content, $at );
	}

	/**
	 * Localiza o fim do wrapper .wp-caption / <figure> que envolve a imagem.
	 *
	 * @param string $content   Conteúdo.
	 * @param int    $img_start Offset de abertura da <img>.
	 * @param int    $fallback  Offset a usar se não houver wrapper.
	 * @return int
	 */
	private static function end_of_caption( $content, $img_start, $fallback ) {
		// Procura a abertura de wrapper mais próxima antes da imagem.
		$before = substr( $content, 0, $img_start );

		if ( ! preg_match_all( '#<(div|figure)[^>]*(?:wp-caption|wp-block-image)[^>]*>#i', $before, $opens, PREG_OFFSET_CAPTURE ) ) {
			return $fallback;
		}

		$open = end( $opens[0] );
		$tag  = strtolower( end( $opens[1] )[0] );

		// Fecha na primeira tag de fechamento correspondente depois da imagem.
		if ( preg_match( '#</' . $tag . '>#i', $content, $close, PREG_OFFSET_CAPTURE, $fallback ) ) {
			return (int) $close[0][1] + strlen( $close[0][0] );
		}

		return $fallback;
	}
}
