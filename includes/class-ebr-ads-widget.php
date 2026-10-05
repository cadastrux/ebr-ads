<?php
/**
 * Widget de anúncio.
 *
 * O QUADS registrava dez classes de widget quase idênticas (widgets.php, 589
 * linhas). Aqui é uma classe só, com o slot escolhido em um <select> — mesmo
 * resultado, um décimo do código e um único ponto de manutenção.
 *
 * §31 do documento de auditoria: sanitização no update(), escape no form(),
 * escape no frontend.
 *
 * @package EBR_Ads
 */

defined( 'ABSPATH' ) || exit;

class EBR_Ads_Widget extends WP_Widget {

	/**
	 * Registra o widget.
	 */
	public static function init() {
		add_action(
			'widgets_init',
			static function () {
				register_widget( 'EBR_Ads_Widget' );
			}
		);
	}

	public function __construct() {
		parent::__construct(
			'ebr_ads_widget',
			__( 'EBR Ads', 'ebr-ads' ),
			array( 'description' => __( 'Exibe um anúncio na sidebar.', 'ebr-ads' ) )
		);
	}

	/**
	 * Saída no frontend.
	 *
	 * @param array $args     Argumentos da sidebar.
	 * @param array $instance Instância salva.
	 */
	public function widget( $args, $instance ) {
		if ( ! EBR_Ads_Conditions::should_display_widget() ) {
			return;
		}
		// A sidebar está fora do loop: o post certo é o da página exibida, e só
		// em páginas singulares. Em arquivos, get_the_ID() seria o último post
		// do loop, e a marcação dele esconderia o widget da listagem inteira.
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
			if ( EBR_Ads_Conditions::post_disabled( $post_id ) || EBR_Ads_Conditions::post_hides( 'widget', $post_id ) ) {
				return;
			}
		}
		if ( ! EBR_Ads_Conditions::can_render_more() ) {
			return;
		}

		$slot = isset( $instance['slot'] ) ? (int) $instance['slot'] : 0;
		if ( $slot < 1 || $slot > EBR_Ads_Schema::AD_SLOTS ) {
			return;
		}

		// Widgets usam os slots ad{N}_widget, separados dos de conteúdo —
		// mesma divisão do QUADS, para que a importação caia no lugar certo.
		$ad   = EBR_Ads_Store::get_ad( 'ad' . $slot . '_widget' );
		$html = EBR_Ads_Renderer::render( $ad, 'widget' );

		if ( '' === $html ) {
			return;
		}

		EBR_Ads_Conditions::count_render();

		// $args vem do tema e é confiável por contrato do WordPress.
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutputOutputNotEscaped

		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] // phpcs:ignore WordPress.Security.EscapeOutputOutputNotEscaped
				. esc_html( $instance['title'] )
				. $args['after_title']; // phpcs:ignore WordPress.Security.EscapeOutputOutputNotEscaped
		}

		// Já montado e escapado (ou intencionalmente cru) pelo renderer.
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutputOutputNotEscaped

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutputOutputNotEscaped
	}

	/**
	 * Formulário no admin. Tudo escapado na saída.
	 *
	 * @param array $instance Instância salva.
	 */
	public function form( $instance ) {
		$title = isset( $instance['title'] ) ? $instance['title'] : '';
		$slot  = isset( $instance['slot'] ) ? (int) $instance['slot'] : 0;
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Título (opcional):', 'ebr-ads' ); ?>
			</label>
			<input class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $title ); ?>">
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'slot' ) ); ?>">
				<?php esc_html_e( 'Anúncio:', 'ebr-ads' ); ?>
			</label>
			<select class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'slot' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'slot' ) ); ?>">
				<option value="0"><?php esc_html_e( '— Selecione —', 'ebr-ads' ); ?></option>
				<?php
				for ( $i = 1; $i <= EBR_Ads_Schema::AD_SLOTS; $i++ ) {
					$ad    = EBR_Ads_Store::get_ad( 'ad' . $i . '_widget' );
					$label = ( $ad && '' !== $ad['label'] ) ? $ad['label'] : 'widget-' . $i;
					printf(
						'<option value="%1$d" %2$s>%3$s</option>',
						(int) $i,
						selected( $slot, $i, false ),
						esc_html( $label )
					);
				}
				?>
			</select>
		</p>
		<?php
	}

	/**
	 * Sanitização ao salvar.
	 *
	 * @param array $new_instance Novo valor.
	 * @param array $old_instance Valor anterior.
	 * @return array
	 */
	public function update( $new_instance, $old_instance ) {
		return array(
			'title' => isset( $new_instance['title'] ) ? sanitize_text_field( $new_instance['title'] ) : '',
			'slot'  => EBR_Ads_Schema::validate_slot(
				isset( $new_instance['slot'] ) ? $new_instance['slot'] : 0
			),
		);
	}
}
