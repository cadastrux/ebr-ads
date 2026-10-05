<?php
/**
 * Posição "After Class": anúncio logo depois de um elemento com uma classe CSS.
 *
 * Diferente das outras posições, o alvo pode estar FORA do conteúdo do post —
 * p.ex. `.cat-content` no template de categoria —, onde o filtro the_content
 * não alcança. Por isso trabalhamos sobre o HTML da página inteira, capturado
 * com output buffering a partir do template_redirect.
 *
 * O QUADS fazia o mesmo passando a página por DOMDocument e devolvendo
 * saveHTML(): o documento inteiro era reescrito pelo parser HTML4 do libxml,
 * que não conhece HTML5 e mexe em entidades, <script> e atributos. Aqui o HTML
 * é tratado como texto: localizamos o elemento, achamos o fechamento
 * correspondente e inserimos o anúncio logo depois. Nenhum outro byte muda.
 *
 * @package EBR_Ads
 */

defined( 'ABSPATH' ) || exit;

class EBR_Ads_Page_Injector {

	/** Elementos sem tag de fechamento: o anúncio entra logo após a abertura. */
	const VOID_TAGS = array( 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' );

	/** Nunca inserir depois destes, mesmo que tenham a classe. */
	const SKIP_TAGS = array( 'html', 'head', 'body' );

	/**
	 * Posições ativas nesta página, montadas antes de abrir o buffer.
	 *
	 * @var array chave => { class, all, html }
	 */
	private static $active = array();

	/**
	 * Registra os hooks.
	 */
	public static function init() {
		// Prioridade alta: as decisões dependem da query já resolvida, e outros
		// plugins que redirecionam neste hook já tiveram a chance de sair.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_buffer' ), 99 );
	}

	/**
	 * Posições "After Class" ativas e com classe válida, na ordem do painel.
	 *
	 * @return array chave => configuração
	 */
	private static function configs() {
		$settings = EBR_Ads_Store::get();
		$out      = array();

		foreach ( EBR_Ads_Schema::positions() as $key => $meta ) {
			if ( empty( $meta['class'] ) || ! isset( $settings['positions'][ $key ] ) ) {
				continue;
			}
			$config = $settings['positions'][ $key ];
			if ( ! empty( $config['enabled'] ) && ! empty( $config['class_name'] ) ) {
				$out[ $key ] = $config;
			}
		}

		return $out;
	}

	/**
	 * Decide, antes do template, se esta página recebe os anúncios; se sim,
	 * abre o buffer.
	 */
	public static function maybe_buffer() {
		$configs = self::configs();
		if ( ! $configs ) {
			return;
		}

		// Decisão da PÁGINA: em singulares vale a marcação do post exibido; em
		// listagens, nenhuma (0) — não a do primeiro post do loop.
		$post_id = is_singular() ? (int) get_queried_object_id() : 0;

		if ( ! EBR_Ads_Conditions::should_display( $post_id ) ) {
			return;
		}
		if ( $post_id && EBR_Ads_Conditions::post_hides( 'auto', $post_id ) ) {
			return;
		}

		// Renderizado aqui, e não dentro do callback do buffer: em um handler de
		// output buffering o PHP não permite ob_start(), e um filtro de terceiro
		// em ebr_ads_render que o usasse derrubaria a página.
		self::$active = array();
		foreach ( $configs as $key => $config ) {
			$html = EBR_Ads_Renderer::render( EBR_Ads_Store::resolve_ad( $config['ad'] ), 'class' );
			if ( '' !== $html ) {
				self::$active[ $key ] = array(
					'class' => $config['class_name'],
					'all'   => ! empty( $config['flag'] ),
					'html'  => $html,
				);
			}
		}

		if ( self::$active ) {
			ob_start( array( __CLASS__, 'filter_page' ) );
		}
	}

	/**
	 * Callback do buffer: insere os anúncios na página pronta.
	 *
	 * @param string $html HTML da página.
	 * @return string
	 */
	public static function filter_page( $html ) {
		if ( ! self::$active || ! is_string( $html ) ) {
			return $html;
		}

		// Só documentos HTML — nada de JSON, XML ou arquivos servidos pelo PHP.
		if ( false === stripos( $html, '<html' ) && false === stripos( $html, '</body' ) ) {
			return $html;
		}

		return self::apply( $html, self::$active );
	}

	/**
	 * Insere os anúncios de todas as posições ativas, numa única passada.
	 *
	 * @param string $html   HTML da página.
	 * @param array  $active chave => { class, all, html }.
	 * @return string
	 */
	public static function apply( $html, array $active ) {
		// Offset => HTML a inserir ali. Duas posições no mesmo ponto saem na
		// ordem do painel (class1 antes de class2).
		$plan = array();

		foreach ( $active as $position ) {
			// Atalho barato antes de qualquer regex.
			if ( false === strpos( $html, $position['class'] ) ) {
				continue;
			}
			foreach ( self::insertion_points( $html, $position['class'], $position['all'] ) as $at ) {
				$plan[ $at ][] = $position['html'];
			}
		}

		if ( ! $plan ) {
			return $html;
		}

		ksort( $plan );

		$out  = '';
		$last = 0;
		foreach ( $plan as $at => $ads ) {
			$chunk = '';
			foreach ( $ads as $ad ) {
				// Estas posições são resolvidas por último na página, então são
				// elas que cedem quando o limite de anúncios já foi atingido.
				if ( ! EBR_Ads_Conditions::can_render_more() ) {
					break 2;
				}
				EBR_Ads_Conditions::count_render();
				$chunk .= $ad;
			}

			$out .= substr( $html, $last, $at - $last ) . $chunk;
			$last = $at;
		}

		return $out . substr( $html, $last );
	}

	/**
	 * Offsets, em ordem crescente, onde o anúncio deve entrar.
	 *
	 * @param string $html  HTML da página.
	 * @param string $class Classe já validada (EBR_Ads_Schema::validate_css_class).
	 * @param bool   $all   Todas as ocorrências, ou só a primeira.
	 * @return int[]
	 */
	public static function insertion_points( $html, $class, $all ) {
		$protected = self::protected_ranges( $html );
		if ( null === $protected ) {
			return array(); // Regex falhou (HTML gigante/patológico): não arriscar.
		}

		// Abertura de tag com a classe como TOKEN do atributo class — "cat-content"
		// não casa "cat-content-wrap" (o QUADS casava substring).
		$pattern = '#<([a-zA-Z][a-zA-Z0-9-]*)(?=[\s/>])[^>]*?\sclass\s*=\s*(["\'])(?:[^"\'>]*?\s)?'
			. preg_quote( $class, '#' )
			. '(?=[\s"\'])[^>]*>#';

		if ( ! preg_match_all( $pattern, $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return array();
		}

		$body   = stripos( $html, '<body' );
		$points = array();

		foreach ( $matches as $m ) {
			$start = $m[0][1];
			$open  = $m[0][0];
			$tag   = strtolower( $m[1][0] );

			if ( in_array( $tag, self::SKIP_TAGS, true ) ) {
				continue;
			}
			if ( false !== $body && $start < $body ) {
				continue; // Nada no <head>.
			}
			if ( self::in_ranges( $start, $protected ) ) {
				continue; // Dentro de <script>, <style>, comentário...
			}

			$open_end = $start + strlen( $open );

			if ( in_array( $tag, self::VOID_TAGS, true ) || '/>' === substr( $open, -2 ) ) {
				$point = $open_end;
			} else {
				$point = self::closing_end( $html, $tag, $open_end, $protected );
				if ( null === $point ) {
					continue; // Sem fechamento identificável: melhor não inserir.
				}
			}

			$points[] = $point;

			if ( ! $all ) {
				break;
			}
		}

		$points = array_values( array_unique( $points ) );
		sort( $points );

		return $points;
	}

	/**
	 * Offset logo após o fechamento do elemento aberto em $from.
	 *
	 * Conta aberturas e fechamentos da MESMA tag (div dentro de div), ignorando
	 * as que estão em trechos protegidos.
	 *
	 * @param string $html      HTML.
	 * @param string $tag       Nome da tag, minúsculo.
	 * @param int    $from      Offset logo após a tag de abertura.
	 * @param array  $protected Trechos protegidos.
	 * @return int|null
	 */
	private static function closing_end( $html, $tag, $from, array $protected ) {
		$re    = '#<(/?)' . preg_quote( $tag, '#' ) . '(?=[\s/>])[^>]*>#i';
		$depth = 1;
		$pos   = $from;

		while ( preg_match( $re, $html, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
			$at  = $m[0][1];
			$pos = $at + strlen( $m[0][0] );

			if ( self::in_ranges( $at, $protected ) ) {
				continue;
			}

			if ( '/' === $m[1][0] ) {
				if ( 0 === --$depth ) {
					return $pos;
				}
			} elseif ( '/>' !== substr( $m[0][0], -2 ) ) {
				$depth++;
			}
		}

		return null;
	}

	/**
	 * Trechos onde marcação não é marcação: comentários e o conteúdo de
	 * <script>, <style>, <textarea>, <noscript>, <template>.
	 *
	 * @param string $html HTML.
	 * @return array|null Lista de [início, fim]; null se a regex falhar.
	 */
	private static function protected_ranges( $html ) {
		$found = preg_match_all(
			'#<!--.*?-->|<(script|style|textarea|noscript|template)\b[^>]*>.*?</\1\s*>#is',
			$html,
			$matches,
			PREG_OFFSET_CAPTURE
		);

		if ( false === $found ) {
			return null;
		}

		$ranges = array();
		foreach ( $matches[0] as $match ) {
			$ranges[] = array( $match[1], $match[1] + strlen( $match[0] ) );
		}

		return $ranges;
	}

	/**
	 * O offset cai dentro de algum trecho protegido?
	 *
	 * @param int   $offset Offset.
	 * @param array $ranges Trechos.
	 * @return bool
	 */
	private static function in_ranges( $offset, array $ranges ) {
		foreach ( $ranges as $range ) {
			if ( $offset >= $range[0] && $offset < $range[1] ) {
				return true;
			}
		}
		return false;
	}
}
