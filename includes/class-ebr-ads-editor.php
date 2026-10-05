<?php
/**
 * Integração com a tela de edição de post.
 *
 * Duas coisas:
 *
 *   1. Metabox "EBR Ads - Ocultar anúncios" — as mesmas oito opções da caixa
 *      "WP QUADS - Hide Ads": ocultar tudo, só os automáticos, só a sidebar ou
 *      posições individuais. Sem ele, as marcações do QUADS seriam legíveis
 *      mas não graváveis pela interface: posts já marcados continuariam como
 *      estavam, mas ninguém conseguiria desmarcá-los nem marcar novos.
 *
 *   2. Botão de shortcode no editor clássico, controlado pela opção `quicktags`
 *      (importada de `quads_settings['quicktags']['QckTags']`).
 *
 * Autorização do metabox: `edit_post` sobre AQUELE post, não `ebr_manage_ads`.
 * É uma decisão editorial sobre o conteúdo — o autor do post deve poder tomá-la,
 * e um gerente de anúncios não deve poder editar posts alheios só por gerenciar
 * anúncios. É a lição do ADS-SEC-005: autorização de objeto, não só de ação.
 *
 * @package EBR_Ads
 */

defined( 'ABSPATH' ) || exit;

class EBR_Ads_Editor {

	const META      = '_ebr_ads_disabled';
	const HIDE_META = '_ebr_ads_hide';
	const LEGACY    = '_quads_config_visibility';
	const NONCE     = 'ebr_ads_post_nonce';

	/**
	 * Registra os hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'quicktags' ) );
	}

	/**
	 * Registra o metabox.
	 *
	 * Em todos os post types públicos, não só nos habilitados para inserção
	 * automática: widget e shortcode aparecem em qualquer página (inclusive em
	 * Páginas, que por padrão não recebem inserção automática), e ocultá-los
	 * precisa ser possível ali também.
	 *
	 * @param string $post_type Post type da tela atual.
	 */
	public static function add_box( $post_type ) {
		if ( ! in_array( $post_type, EBR_Ads_Schema::allowed_post_types(), true ) ) {
			return;
		}

		add_meta_box(
			'ebr-ads-box',
			__( 'EBR Ads - Ocultar anúncios', 'ebr-ads' ),
			array( __CLASS__, 'render_box' ),
			$post_type,
			'advanced',
			'default'
		);
	}

	/**
	 * Conteúdo do metabox.
	 *
	 * @param WP_Post $post Post em edição.
	 */
	public static function render_box( $post ) {
		wp_nonce_field( 'ebr_ads_save_post', self::NONCE );

		// A meta do QUADS é somente leitura aqui: mostramos que ela está ativa,
		// mas quem grava é a nossa. Salvar o metabox migra a decisão.
		$legacy = get_post_meta( $post->ID, self::LEGACY, true );
		$legacy = is_array( $legacy ) ? $legacy : array();
		$ours   = self::own_flags( $post->ID );

		$from_quads = false;
		foreach ( EBR_Ads_Schema::post_hide_options() as $key => $option ) {
			$is_legacy = ! empty( $legacy[ $option['legacy'] ] );
			if ( $is_legacy && ! in_array( $key, $ours, true ) ) {
				$from_quads = true;
			}
			?>
			<p>
				<label>
					<input type="checkbox" name="ebr_ads_hide[]" value="<?php echo esc_attr( $key ); ?>"
						<?php checked( $is_legacy || in_array( $key, $ours, true ) ); ?>>
					<?php echo esc_html( $option['label'] ); ?>
				</label>
			</p>
			<?php
		}
		?>
		<?php if ( $from_quads ) : ?>
			<p class="description">
				<?php esc_html_e( 'Parte destas opções já estava marcada no Quick AdSense Reloaded. Ao salvar, a marcação passa a ser gravada por este plugin.', 'ebr-ads' ); ?>
			</p>
		<?php endif; ?>
		<p class="description">
			<?php esc_html_e( '"Ocultar todos" vale para inserção automática, shortcode e widget. As opções de posição só afetam a inserção automática.', 'ebr-ads' ); ?>
		</p>
		<?php
	}

	/**
	 * Opções marcadas na nossa própria meta (sem a do QUADS).
	 *
	 * @param int $post_id ID do post.
	 * @return string[]
	 */
	private static function own_flags( $post_id ) {
		$flags = get_post_meta( $post_id, self::HIDE_META, true );
		$flags = is_array( $flags ) ? $flags : array();

		if ( get_post_meta( $post_id, self::META, true ) ) {
			$flags[] = 'all';
		}

		return $flags;
	}

	/**
	 * Salva a meta.
	 *
	 * @param int     $post_id ID do post.
	 * @param WP_Post $post    Objeto do post.
	 */
	public static function save( $post_id, $post ) {
		// 1. Autosave e revisões não carregam o formulário — sair sem tocar em nada.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// 2. O metabox precisa ter sido realmente renderizado nesta requisição.
		//    Sem isso, salvamentos via REST/quick-edit apagariam a meta por omissão.
		if ( ! isset( $_POST[ self::NONCE ] ) ) {
			return;
		}

		// 3. CSRF.
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'ebr_ads_save_post' ) ) {
			return;
		}

		// 4. Autorização de OBJETO: este usuário pode editar ESTE post?
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// 5. O post type precisa ser um dos que recebem o metabox.
		if ( ! in_array( $post->post_type, EBR_Ads_Schema::allowed_post_types(), true ) ) {
			return;
		}

		// 6. Allowlist: só chaves conhecidas sobrevivem; o resto é descartado.
		$options = EBR_Ads_Schema::post_hide_options();
		$raw     = isset( $_POST['ebr_ads_hide'] ) && is_array( $_POST['ebr_ads_hide'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['ebr_ads_hide'] ) )
			: array();
		$checked = array_values( array_intersect( array_keys( $options ), $raw ) );

		// 'all' continua na meta original; as demais numa lista própria.
		if ( in_array( 'all', $checked, true ) ) {
			update_post_meta( $post_id, self::META, 1 );
		} else {
			delete_post_meta( $post_id, self::META );
		}

		$others = array_values( array_diff( $checked, array( 'all' ) ) );
		if ( $others ) {
			update_post_meta( $post_id, self::HIDE_META, $others );
		} else {
			delete_post_meta( $post_id, self::HIDE_META );
		}

		// Desmarcar precisa vencer também a meta legada do QUADS; caso
		// contrário o post continuaria sem anúncios sem explicação visível.
		$legacy = get_post_meta( $post_id, self::LEGACY, true );
		if ( is_array( $legacy ) && $legacy ) {
			$changed = false;
			foreach ( $options as $key => $option ) {
				if ( ! in_array( $key, $checked, true ) && ! empty( $legacy[ $option['legacy'] ] ) ) {
					unset( $legacy[ $option['legacy'] ] );
					$changed = true;
				}
			}
			if ( $changed ) {
				update_post_meta( $post_id, self::LEGACY, $legacy );
			}
		}
	}

	/**
	 * Botão de shortcode no editor clássico (aba Texto).
	 *
	 * @param string $hook Hook da tela atual.
	 */
	public static function quicktags( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$settings = EBR_Ads_Store::get();
		if ( empty( $settings['quicktags'] ) ) {
			return;
		}

		wp_enqueue_script(
			'ebr-ads-quicktags',
			EBR_ADS_URL . 'assets/quicktags.js',
			array( 'quicktags' ),
			EBR_ADS_VERSION,
			true
		);

		// Lista de anúncios que têm conteúdo, para o botão oferecer opções reais.
		$ads = array();
		for ( $i = 1; $i <= EBR_Ads_Schema::AD_SLOTS; $i++ ) {
			$ad = EBR_Ads_Store::get_ad_by_index( $i );
			if ( EBR_Ads_Store::ad_has_content( $ad ) ) {
				$ads[] = array(
					'id'    => $i,
					'label' => ( '' !== $ad['label'] ) ? $ad['label'] : 'ad-' . $i,
				);
			}
		}

		wp_localize_script(
			'ebr-ads-quicktags',
			'ebrAdsQuicktags',
			array(
				'button' => __( 'Anúncio', 'ebr-ads' ),
				'title'  => __( 'Inserir shortcode de anúncio', 'ebr-ads' ),
				'prompt' => __( 'Número do anúncio a inserir:', 'ebr-ads' ),
				'none'   => __( 'Nenhum anúncio configurado ainda.', 'ebr-ads' ),
				'ads'    => $ads,
			)
		);
	}
}
