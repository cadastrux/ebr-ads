<?php
/**
 * Painel administrativo.
 *
 * Replica o layout clássico do WP QUADS: abas General / Import-Export / Help,
 * com sub-abas General & Position / Ads / Widget Ads / Plugin Settings.
 * Sem o box "Switch to New Panel" e sem o banner de upsell.
 *
 * Controle de acesso, em todas as telas e handlers:
 *   1. capability (EBR_Ads_Caps::can_manage())
 *   2. nonce (check_admin_referer)
 *   3. sanitização por schema
 *   4. escape na saída
 *
 * Os quatro passos, sempre nessa ordem. §6: nonce não substitui autorização.
 *
 * @package EBR_Ads
 */

defined( 'ABSPATH' ) || exit;

class EBR_Ads_Admin {

	const SLUG  = 'ebr-ads';
	const NONCE = 'ebr_ads_nonce';

	/**
	 * Registra hooks do admin.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_ebr_ads_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_ebr_ads_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_ebr_ads_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_ebr_ads_reimport', array( __CLASS__, 'handle_reimport' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		add_menu_page(
			__( 'EBR Ads', 'ebr-ads' ),
			__( 'EBR Ads', 'ebr-ads' ),
			EBR_Ads_Caps::MANAGE,
			self::SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-megaphone',
			81
		);
	}

	/**
	 * CSS/JS só na nossa tela.
	 *
	 * @param string $hook Hook da tela atual.
	 */
	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'ebr-ads-admin', EBR_ADS_URL . 'assets/admin.css', array(), EBR_ADS_VERSION );
		wp_enqueue_script( 'ebr-ads-admin', EBR_ADS_URL . 'assets/admin.js', array(), EBR_ADS_VERSION, true );
	}

	/** Abas de primeiro nível. */
	private static function tabs() {
		return array(
			'general'   => __( 'General', 'ebr-ads' ),
			'imexport'  => __( 'Import/Export', 'ebr-ads' ),
			'help'      => __( 'Help', 'ebr-ads' ),
		);
	}

	/** Sub-abas da aba General. */
	private static function subtabs() {
		return array(
			'position' => __( 'General & Position', 'ebr-ads' ),
			'ads'      => __( 'Ads', 'ebr-ads' ),
			'widgets'  => __( 'Widget Ads', 'ebr-ads' ),
			'settings' => __( 'Plugin Settings', 'ebr-ads' ),
		);
	}

	/**
	 * Aba ativa, validada contra allowlist.
	 *
	 * §11: parâmetros GET nunca chegam ao HTML sem passar por uma lista fechada.
	 *
	 * @param string $param   Nome do parâmetro.
	 * @param array  $allowed Valores aceitos.
	 * @param string $default Padrão.
	 * @return string
	 */
	private static function current( $param, array $allowed, $default ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navegação, não altera estado.
		$value = isset( $_GET[ $param ] ) ? sanitize_key( wp_unslash( $_GET[ $param ] ) ) : '';
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	/**
	 * Renderiza a página.
	 */
	public static function render_page() {
		if ( ! EBR_Ads_Caps::can_manage() ) {
			wp_die( esc_html__( 'Você não possui permissão para acessar esta página.', 'ebr-ads' ) );
		}

		$tab    = self::current( 'tab', array_keys( self::tabs() ), 'general' );
		$subtab = self::current( 'subtab', array_keys( self::subtabs() ), 'position' );

		echo '<div class="wrap ebr-ads">';
		printf( '<h1>%s</h1>', esc_html__( 'EBR Ads', 'ebr-ads' ) );

		self::notices();

		// Abas de primeiro nível.
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( self::tabs() as $key => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab %2$s">%3$s</a>',
				esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $key ), admin_url( 'admin.php' ) ) ),
				$key === $tab ? 'nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h2>';

		switch ( $tab ) {
			case 'imexport':
				self::render_imexport();
				break;
			case 'help':
				self::render_help();
				break;
			default:
				self::render_general( $subtab );
		}

		echo '</div>';
	}

	/**
	 * Mensagens de resultado.
	 */
	private static function notices() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- apenas exibição.
		$msg = isset( $_GET['ebr_msg'] ) ? sanitize_key( wp_unslash( $_GET['ebr_msg'] ) ) : '';
		if ( '' === $msg ) {
			return;
		}

		// Allowlist: a mensagem exibida vem daqui, nunca da URL.
		$map = array(
			'saved'        => array( 'success', __( 'Configurações salvas.', 'ebr-ads' ) ),
			'imported'     => array( 'success', __( 'Configurações importadas.', 'ebr-ads' ) ),
			'reimported'   => array( 'success', __( 'Dados do QUADS reimportados.', 'ebr-ads' ) ),
			'nofile'       => array( 'error', __( 'Nenhum arquivo enviado.', 'ebr-ads' ) ),
			'badtype'      => array( 'error', __( 'O arquivo precisa ser .json.', 'ebr-ads' ) ),
			'badjson'      => array( 'error', __( 'O arquivo não contém JSON válido. Nada foi alterado.', 'ebr-ads' ) ),
			'toobig'       => array( 'error', __( 'Arquivo grande demais (máximo 1 MB).', 'ebr-ads' ) ),
			'uploaderror'  => array( 'error', __( 'Falha no upload do arquivo.', 'ebr-ads' ) ),
		);

		if ( ! isset( $map[ $msg ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $map[ $msg ][0] ),
			esc_html( $map[ $msg ][1] )
		);
	}

	/**
	 * Aba General com suas sub-abas.
	 *
	 * @param string $subtab Sub-aba ativa.
	 */
	private static function render_general( $subtab ) {
		echo '<h3 class="nav-tab-wrapper ebr-subtabs">';
		foreach ( self::subtabs() as $key => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab %2$s">%3$s</a>',
				esc_url(
					add_query_arg(
						array( 'page' => self::SLUG, 'tab' => 'general', 'subtab' => $key ),
						admin_url( 'admin.php' )
					)
				),
				$key === $subtab ? 'nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</h3>';

		$settings = EBR_Ads_Store::get();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ebr_ads_save', self::NONCE );
		echo '<input type="hidden" name="action" value="ebr_ads_save">';
		printf( '<input type="hidden" name="section" value="%s">', esc_attr( $subtab ) );

		switch ( $subtab ) {
			case 'ads':
				self::render_ads( $settings, false );
				break;
			case 'widgets':
				self::render_ads( $settings, true );
				break;
			case 'settings':
				self::render_settings( $settings );
				break;
			default:
				self::render_position( $settings );
		}

		submit_button( __( 'Salvar alterações', 'ebr-ads' ) );
		echo '</form>';
	}

	/**
	 * Sub-aba "General & Position".
	 *
	 * @param array $settings Settings.
	 */
	private static function render_position( array $settings ) {
		$positions = $settings['positions'];
		?>
		<h2><?php esc_html_e( 'General & Position', 'ebr-ads' ); ?></h2>

		<h3><?php esc_html_e( 'Limit Amount of ads:', 'ebr-ads' ); ?></h3>
		<p>
			<select name="max_ads">
				<option value="0" <?php selected( (int) $settings['max_ads'], 0 ); ?>>
					<?php esc_html_e( 'unlimited', 'ebr-ads' ); ?>
				</option>
				<?php for ( $i = 1; $i <= 20; $i++ ) : ?>
					<option value="<?php echo esc_attr( $i ); ?>" <?php selected( (int) $settings['max_ads'], $i ); ?>>
						<?php echo esc_html( $i ); ?>
					</option>
				<?php endfor; ?>
			</select>
			<?php esc_html_e( 'anúncios por página.', 'ebr-ads' ); ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Este limite vale para inserção automática, shortcode e widget somados. Ele é a proteção da sua conta AdSense contra excesso de blocos numa mesma página.', 'ebr-ads' ); ?>
		</p>

		<h3><?php esc_html_e( 'Position - Default Ads', 'ebr-ads' ); ?></h3>
		<table class="ebr-positions">
			<tbody>
			<?php
			self::position_row( 'begin', $positions, __( 'to Beginning of Post', 'ebr-ads' ) );
			self::position_row( 'middle', $positions, __( 'to Middle of Post', 'ebr-ads' ) );
			self::position_row( 'end', $positions, __( 'to End of Post', 'ebr-ads' ) );
			self::position_row( 'more', $positions, __( 'right after the <!--more--> tag', 'ebr-ads' ) );
			self::position_row( 'last_para', $positions, __( 'right before the last Paragraph', 'ebr-ads' ) );

			self::position_row(
				'para1',
				$positions,
				__( 'After Paragraph', 'ebr-ads' ),
				__( 'to End of Post if fewer paragraphs are found.', 'ebr-ads' )
			);
			self::position_row(
				'para2',
				$positions,
				__( 'After Paragraph', 'ebr-ads' ),
				__( 'to End of Post if fewer paragraphs are found.', 'ebr-ads' )
			);
			self::position_row(
				'para3',
				$positions,
				__( 'After Paragraph', 'ebr-ads' ),
				__( 'to End of Post if fewer paragraphs are found.', 'ebr-ads' )
			);
			self::position_row(
				'image1',
				$positions,
				__( 'After Image', 'ebr-ads' ),
				__( 'after Image\'s outer <div> wp-caption if any.', 'ebr-ads' )
			);
			?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Visibility', 'ebr-ads' ); ?></h3>
		<p>
			<?php
			self::checkbox( 'visibility[home]', $settings['visibility']['home'], __( 'Homepage', 'ebr-ads' ) );
			self::checkbox( 'visibility[category]', $settings['visibility']['category'], __( 'Categories', 'ebr-ads' ) );
			self::checkbox( 'visibility[archive]', $settings['visibility']['archive'], __( 'Archives', 'ebr-ads' ) );
			self::checkbox( 'visibility[tag]', $settings['visibility']['tag'], __( 'Tags', 'ebr-ads' ) );
			?>
		</p>
		<p>
			<?php self::checkbox( 'visibility[hide_widget_home]', $settings['visibility']['hide_widget_home'], __( 'Hide Ad Widgets on Homepage', 'ebr-ads' ) ); ?>
		</p>
		<p>
			<?php self::checkbox( 'visibility[hide_logged_in]', $settings['visibility']['hide_logged_in'], __( 'Hide Ads when user is logged in.', 'ebr-ads' ) ); ?>
		</p>

		<h3><?php esc_html_e( 'Post Types', 'ebr-ads' ); ?></h3>
		<select name="post_types[]" multiple size="6" class="ebr-posttypes">
			<?php foreach ( EBR_Ads_Schema::allowed_post_types() as $type ) : ?>
				<option value="<?php echo esc_attr( $type ); ?>"
					<?php selected( in_array( $type, (array) $settings['post_types'], true ) ); ?>>
					<?php echo esc_html( $type ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'A inserção automática só ocorre nos post types selecionados. Shortcode e widget não dependem desta lista.', 'ebr-ads' ); ?>
		</p>
		<?php
	}

	/**
	 * Uma linha da tabela de posições.
	 *
	 * @param string $key       Chave da posição.
	 * @param array  $positions Todas as posições.
	 * @param string $label     Texto após o select.
	 * @param string $flag_text Texto da opção extra, se houver.
	 */
	private static function position_row( $key, array $positions, $label, $flag_text = '' ) {
		$config  = $positions[ $key ];
		$counted = '' !== $flag_text;
		?>
		<tr>
			<td class="ebr-col-check">
				<label>
					<input type="checkbox"
						class="ebr-assign"
						name="positions[<?php echo esc_attr( $key ); ?>][enabled]"
						value="1" <?php checked( ! empty( $config['enabled'] ) ); ?>>
					<?php esc_html_e( 'Assign', 'ebr-ads' ); ?>
				</label>
			</td>
			<td class="ebr-col-ad">
				<?php self::ad_select( 'positions[' . $key . '][ad]', (int) $config['ad'], empty( $config['enabled'] ) ); ?>
			</td>
			<td class="ebr-col-label">
				<?php
				// $label é literal de tradução, não entrada de usuário. Contém
				// <!--more--> e <div>, que precisam aparecer como texto.
				echo esc_html( $label );
				?>
				<?php if ( $counted ) : ?>
					<select name="positions[<?php echo esc_attr( $key ); ?>][count]" class="ebr-count">
						<?php for ( $i = 1; $i <= 20; $i++ ) : ?>
							<option value="<?php echo esc_attr( $i ); ?>" <?php selected( (int) $config['count'], $i ); ?>>
								<?php echo esc_html( $i ); ?>
							</option>
						<?php endfor; ?>
					</select>
					<span class="ebr-arrow">&rarr;</span>
					<label>
						<input type="checkbox"
							name="positions[<?php echo esc_attr( $key ); ?>][flag]"
							value="1" <?php checked( ! empty( $config['flag'] ) ); ?>>
						<?php echo esc_html( $flag_text ); ?>
					</label>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Select de anúncio (0 = Random Ads, 1..10 = ad1..ad10).
	 *
	 * @param string $name     Atributo name.
	 * @param int    $selected Valor atual.
	 * @param bool   $disabled Renderizar desabilitado.
	 */
	private static function ad_select( $name, $selected, $disabled = false ) {
		printf(
			'<select name="%1$s" class="ebr-ad-select"%2$s>',
			esc_attr( $name ),
			$disabled ? ' disabled' : ''
		);

		printf(
			'<option value="0" %1$s>%2$s</option>',
			selected( $selected, 0, false ),
			esc_html__( 'Random Ads', 'ebr-ads' )
		);

		for ( $i = 1; $i <= EBR_Ads_Schema::AD_SLOTS; $i++ ) {
			$ad    = EBR_Ads_Store::get_ad_by_index( $i );
			$label = ( $ad && '' !== $ad['label'] ) ? $ad['label'] : 'ad-' . $i;
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				(int) $i,
				selected( $selected, $i, false ),
				esc_html( $label )
			);
		}

		echo '</select>';
	}

	/**
	 * Sub-abas "Ads" e "Widget Ads".
	 *
	 * @param array $settings Settings.
	 * @param bool  $widget   True para os slots de widget.
	 */
	private static function render_ads( array $settings, $widget ) {
		$can_code = EBR_Ads_Caps::can_manage_code();
		?>
		<h2><?php echo $widget ? esc_html__( 'Widget Ads', 'ebr-ads' ) : esc_html__( 'Ads', 'ebr-ads' ); ?></h2>

		<?php if ( ! $can_code ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php esc_html_e( 'Você pode editar rótulo, alinhamento e margem. O campo de código exige a permissão "ebr_manage_ad_code" e está somente para leitura — o conteúdo atual será preservado ao salvar.', 'ebr-ads' ); ?>
				</p>
			</div>
		<?php endif; ?>

		<?php
		for ( $i = 1; $i <= EBR_Ads_Schema::AD_SLOTS; $i++ ) :
			$slot = $widget ? 'ad' . $i . '_widget' : 'ad' . $i;
			$ad   = $settings['ads'][ $slot ];
			$name = 'ads[' . $slot . ']';
			?>
			<div class="ebr-ad-box">
				<h3>
					<?php echo esc_html( '' !== $ad['label'] ? $ad['label'] : $slot ); ?>
					<span class="ebr-slot-id"><?php echo esc_html( $slot ); ?></span>
				</h3>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Rótulo', 'ebr-ads' ); ?></label></th>
						<td>
							<input type="text" class="regular-text"
								name="<?php echo esc_attr( $name ); ?>[label]"
								value="<?php echo esc_attr( $ad['label'] ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Tipo', 'ebr-ads' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( $name ); ?>[type]" class="ebr-type">
								<option value="code" <?php selected( $ad['type'], 'code' ); ?>>
									<?php esc_html_e( 'Código (AdSense, HTML)', 'ebr-ads' ); ?>
								</option>
								<option value="image" <?php selected( $ad['type'], 'image' ); ?>>
									<?php esc_html_e( 'Imagem + link (sem JavaScript)', 'ebr-ads' ); ?>
								</option>
							</select>
						</td>
					</tr>
					<tr class="ebr-row-code">
						<th scope="row"><label><?php esc_html_e( 'Código', 'ebr-ads' ); ?></label></th>
						<td>
							<textarea class="large-text code" rows="6"
								name="<?php echo esc_attr( $name ); ?>[code]"
								<?php echo $can_code ? '' : 'readonly disabled'; ?>><?php
									echo esc_textarea( $ad['code'] );
								?></textarea>
						</td>
					</tr>
					<tr class="ebr-row-image">
						<th scope="row"><label><?php esc_html_e( 'Imagem (ID)', 'ebr-ads' ); ?></label></th>
						<td>
							<input type="number" min="0" class="small-text"
								name="<?php echo esc_attr( $name ); ?>[image_id]"
								value="<?php echo esc_attr( $ad['image_id'] ); ?>">
							<p class="description"><?php esc_html_e( 'ID do anexo na Biblioteca de Mídia.', 'ebr-ads' ); ?></p>
						</td>
					</tr>
					<tr class="ebr-row-image">
						<th scope="row"><label><?php esc_html_e( 'URL de destino', 'ebr-ads' ); ?></label></th>
						<td>
							<input type="url" class="regular-text"
								name="<?php echo esc_attr( $name ); ?>[link_url]"
								value="<?php echo esc_attr( $ad['link_url'] ); ?>">
						</td>
					</tr>
					<tr class="ebr-row-image">
						<th scope="row"><label><?php esc_html_e( 'Texto alternativo', 'ebr-ads' ); ?></label></th>
						<td>
							<input type="text" class="regular-text"
								name="<?php echo esc_attr( $name ); ?>[alt]"
								value="<?php echo esc_attr( $ad['alt'] ); ?>">
						</td>
					</tr>
					<tr class="ebr-row-image">
						<th scope="row"><label><?php esc_html_e( 'Abrir em', 'ebr-ads' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( $name ); ?>[target]">
								<option value="_blank" <?php selected( $ad['target'], '_blank' ); ?>>
									<?php esc_html_e( 'Nova aba', 'ebr-ads' ); ?>
								</option>
								<option value="_self" <?php selected( $ad['target'], '_self' ); ?>>
									<?php esc_html_e( 'Mesma aba', 'ebr-ads' ); ?>
								</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Alinhamento', 'ebr-ads' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( $name ); ?>[align]">
								<?php
								$align_labels = array(
									'none'   => __( 'Nenhum', 'ebr-ads' ),
									'left'   => __( 'Esquerda', 'ebr-ads' ),
									'center' => __( 'Centro', 'ebr-ads' ),
									'right'  => __( 'Direita', 'ebr-ads' ),
								);
								foreach ( $align_labels as $value => $text ) {
									printf(
										'<option value="%1$s" %2$s>%3$s</option>',
										esc_attr( $value ),
										selected( $ad['align'], $value, false ),
										esc_html( $text )
									);
								}
								?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Margem (px)', 'ebr-ads' ); ?></label></th>
						<td>
							<input type="number" min="0" max="200" class="small-text"
								name="<?php echo esc_attr( $name ); ?>[margin]"
								value="<?php echo esc_attr( $ad['margin'] ); ?>">
						</td>
					</tr>
				</table>

				<?php if ( ! $widget ) : ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: shortcode */
							esc_html__( 'Shortcode: %s', 'ebr-ads' ),
							'<code>[ebr_ad id="' . (int) $i . '"]</code>'
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		<?php endfor; ?>
		<?php
	}

	/**
	 * Sub-aba "Plugin Settings".
	 *
	 * @param array $settings Settings.
	 */
	private static function render_settings( array $settings ) {
		?>
		<h2><?php esc_html_e( 'Plugin Settings', 'ebr-ads' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Editor', 'ebr-ads' ); ?></th>
				<td>
					<?php self::checkbox( 'quicktags', $settings['quicktags'], __( 'Mostrar botão de shortcode no editor', 'ebr-ads' ) ); ?>
					<p class="description">
						<?php esc_html_e( 'Adiciona um botão "Anúncio" na aba Texto do editor clássico. O editor de blocos não usa quicktags — nele, insira um bloco de Shortcode.', 'ebr-ads' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<h3><?php esc_html_e( 'Permissões', 'ebr-ads' ); ?></h3>
		<table class="widefat striped ebr-caps">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Capability', 'ebr-ads' ); ?></th>
					<th><?php esc_html_e( 'Permite', 'ebr-ads' ); ?></th>
					<th><?php esc_html_e( 'Você tem', 'ebr-ads' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>ebr_manage_ads</code></td>
					<td><?php esc_html_e( 'Gerenciar anúncios, posições e visibilidade.', 'ebr-ads' ); ?></td>
					<td><?php echo EBR_Ads_Caps::can_manage() ? '✓' : '—'; ?></td>
				</tr>
				<tr>
					<td><code>ebr_manage_ad_code</code></td>
					<td><?php esc_html_e( 'Gravar HTML/JavaScript bruto no campo Código.', 'ebr-ads' ); ?></td>
					<td><?php echo EBR_Ads_Caps::can_manage_code() ? '✓' : '—'; ?></td>
				</tr>
			</tbody>
		</table>
		<p class="description">
			<?php esc_html_e( 'As duas capabilities são concedidas ao administrador na ativação. Para dar acesso a outra role, use add_cap() — não existe mapa de roles gravável pela interface, de propósito.', 'ebr-ads' ); ?>
		</p>
		<?php
	}

	/**
	 * Aba Import/Export.
	 */
	private static function render_imexport() {
		?>
		<h2><?php esc_html_e( 'Import / Export', 'ebr-ads' ); ?></h2>

		<h3><?php esc_html_e( 'Exportar', 'ebr-ads' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Baixa toda a configuração em JSON, para replicar em outro site.', 'ebr-ads' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ebr_ads_export', self::NONCE ); ?>
			<input type="hidden" name="action" value="ebr_ads_export">
			<?php submit_button( __( 'Exportar configurações', 'ebr-ads' ), 'secondary', 'submit', false ); ?>
		</form>

		<hr>

		<h3><?php esc_html_e( 'Importar', 'ebr-ads' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'O arquivo é validado antes de qualquer gravação. Se o JSON for inválido, nada é alterado.', 'ebr-ads' ); ?>
		</p>
		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ebr_ads_import', self::NONCE ); ?>
			<input type="hidden" name="action" value="ebr_ads_import">
			<input type="file" name="ebr_import" accept=".json,application/json" required>
			<?php submit_button( __( 'Importar', 'ebr-ads' ), 'secondary', 'submit', false ); ?>
		</form>

		<hr>

		<h3><?php esc_html_e( 'Reimportar do QUADS', 'ebr-ads' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'Lê de novo wp_options[quads_settings] e o CPT quads-ads. Substitui a configuração atual deste plugin. Nada é escrito de volta no QUADS.', 'ebr-ads' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			onsubmit="return confirm('<?php echo esc_js( __( 'Isto substitui a configuração atual do EBR Ads. Continuar?', 'ebr-ads' ) ); ?>');">
			<?php wp_nonce_field( 'ebr_ads_reimport', self::NONCE ); ?>
			<input type="hidden" name="action" value="ebr_ads_reimport">
			<?php submit_button( __( 'Reimportar agora', 'ebr-ads' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Aba Help.
	 */
	private static function render_help() {
		?>
		<h2><?php esc_html_e( 'Help', 'ebr-ads' ); ?></h2>

		<h3><?php esc_html_e( 'Shortcodes', 'ebr-ads' ); ?></h3>
		<table class="widefat striped">
			<tbody>
				<tr>
					<td><code>[ebr_ad id="1"]</code></td>
					<td><?php esc_html_e( 'Exibe o anúncio do slot 1.', 'ebr-ads' ); ?></td>
				</tr>
				<tr>
					<td><code>[quads_ad id="1"]</code>, <code>[quads id="1"]</code></td>
					<td><?php esc_html_e( 'Aliases de compatibilidade com o QUADS. O conteúdo já publicado continua funcionando.', 'ebr-ads' ); ?></td>
				</tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Desligar anúncios em um post específico', 'ebr-ads' ); ?></h3>
		<p>
			<?php esc_html_e( 'Na tela de edição do post ou da página, use a caixa "EBR Ads - Ocultar anúncios", abaixo do conteúdo. Dá para ocultar todos os anúncios, só os automáticos, só os da sidebar ou posições específicas (início, meio, fim, após o more, antes do último parágrafo).', 'ebr-ads' ); ?>
		</p>
		<p>
			<?php
			printf(
				/* translators: 1: our meta key for "hide all", 2: our meta key for the other options, 3: QUADS meta key */
				esc_html__( 'Por trás disso estão as post metas %1$s e %2$s. As marcações equivalentes do QUADS (%3$s) continuam sendo respeitadas, e são migradas para as nossas quando o post é salvo.', 'ebr-ads' ),
				'<code>_ebr_ads_disabled</code>',
				'<code>_ebr_ads_hide</code>',
				'<code>_quads_config_visibility</code>'
			);
			?>
		</p>

		<h3><?php esc_html_e( 'O que este plugin não faz', 'ebr-ads' ); ?></h3>
		<ul class="ul-disc">
			<li><?php esc_html_e( 'Não registra impressões nem cliques. Nenhuma tabela própria é criada.', 'ebr-ads' ); ?></li>
			<li><?php esc_html_e( 'Não expõe nenhum endpoint REST ou AJAX — nem público, nem autenticado.', 'ebr-ads' ); ?></li>
			<li><?php esc_html_e( 'Não coleta IP, user agent, referrer nem qualquer dado de visitante.', 'ebr-ads' ); ?></li>
			<li><?php esc_html_e( 'Não envia dados a serviços externos. Não há telemetria nem licenciamento. A única conexão de saída é a consulta de novas versões nas releases do GitHub (somente leitura, sem enviar a URL do site).', 'ebr-ads' ); ?></li>
			<li><?php esc_html_e( 'Não grava arquivos no disco (inclusive ads.txt).', 'ebr-ads' ); ?></li>
		</ul>
		<?php
	}

	/**
	 * Checkbox com label.
	 *
	 * @param string $name    Atributo name.
	 * @param bool   $checked Estado.
	 * @param string $label   Texto.
	 */
	private static function checkbox( $name, $checked, $label ) {
		printf(
			'<label class="ebr-cb"><input type="checkbox" name="%1$s" value="1" %2$s> %3$s</label>',
			esc_attr( $name ),
			checked( (bool) $checked, true, false ),
			esc_html( $label )
		);
	}

	// ---------------------------------------------------------------------
	// Handlers
	// ---------------------------------------------------------------------

	/**
	 * Autorização comum a todos os handlers.
	 *
	 * Ordem: nonce (CSRF) e capability (autorização) — as duas, sempre.
	 *
	 * @param string $action Ação do nonce.
	 */
	private static function guard( $action ) {
		check_admin_referer( $action, self::NONCE );

		if ( ! EBR_Ads_Caps::can_manage() ) {
			wp_die(
				esc_html__( 'Você não possui permissão para esta ação.', 'ebr-ads' ),
				'',
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Redireciona de volta ao painel com uma mensagem.
	 *
	 * @param string $msg    Chave da mensagem (allowlist em notices()).
	 * @param string $tab    Aba.
	 * @param string $subtab Sub-aba.
	 */
	private static function redirect( $msg, $tab = 'general', $subtab = 'position' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::SLUG,
					'tab'     => $tab,
					'subtab'  => $subtab,
					'ebr_msg' => $msg,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Salva uma sub-aba.
	 *
	 * A tela envia só a sua seção. Fazemos merge sobre os settings atuais antes
	 * de sanitizar, para que as outras seções não sejam zeradas.
	 */
	public static function handle_save() {
		self::guard( 'ebr_ads_save' );

		$existing = EBR_Ads_Store::get();
		$section  = isset( $_POST['section'] ) ? sanitize_key( wp_unslash( $_POST['section'] ) ) : '';

		if ( ! array_key_exists( $section, self::subtabs() ) ) {
			self::redirect( 'saved' );
		}

		$merged = $existing;

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitização integral em EBR_Ads_Schema::sanitize_settings().
		switch ( $section ) {
			case 'position':
				$posted_pos = isset( $_POST['positions'] ) ? (array) wp_unslash( $_POST['positions'] ) : array();

				foreach ( array_keys( EBR_Ads_Schema::positions() ) as $pos_key ) {
					$row = isset( $posted_pos[ $pos_key ] ) && is_array( $posted_pos[ $pos_key ] )
						? $posted_pos[ $pos_key ]
						: array();

					// Checkboxes ausentes significam "desmarcado"; selects
					// ausentes significam "estava desabilitado no formulário",
					// e nesse caso preservamos o valor já configurado.
					$merged['positions'][ $pos_key ]['enabled'] = ! empty( $row['enabled'] );
					$merged['positions'][ $pos_key ]['flag']    = ! empty( $row['flag'] );

					if ( isset( $row['ad'] ) ) {
						$merged['positions'][ $pos_key ]['ad'] = $row['ad'];
					}
					if ( isset( $row['count'] ) ) {
						$merged['positions'][ $pos_key ]['count'] = $row['count'];
					}
				}

				$merged['max_ads']    = isset( $_POST['max_ads'] ) ? wp_unslash( $_POST['max_ads'] ) : 0;
				$merged['visibility'] = isset( $_POST['visibility'] ) ? (array) wp_unslash( $_POST['visibility'] ) : array();
				$merged['post_types'] = isset( $_POST['post_types'] ) ? (array) wp_unslash( $_POST['post_types'] ) : array();
				break;

			case 'ads':
			case 'widgets':
				$posted = isset( $_POST['ads'] ) ? (array) wp_unslash( $_POST['ads'] ) : array();
				foreach ( $posted as $slot => $values ) {
					$slot = sanitize_key( $slot );
					if ( isset( $merged['ads'][ $slot ] ) && is_array( $values ) ) {
						$merged['ads'][ $slot ] = array_merge( $merged['ads'][ $slot ], $values );
					}
				}
				break;

			case 'settings':
				$merged['quicktags'] = ! empty( $_POST['quicktags'] );
				break;
		}
		// phpcs:enable

		EBR_Ads_Store::save( EBR_Ads_Schema::sanitize_settings( $merged, $existing ) );

		self::redirect( 'saved', 'general', $section );
	}

	/**
	 * Exporta as configurações em JSON.
	 */
	public static function handle_export() {
		self::guard( 'ebr_ads_export' );

		$settings = EBR_Ads_Store::get();
		$filename = 'ebr-ads-' . gmdate( 'Y-m-d' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		echo wp_json_encode( $settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Importa configurações de um arquivo JSON.
	 *
	 * ADS-SEC-002 corrigido: cada validação ABORTA. Nada é gravado antes de o
	 * JSON ter sido decodificado com sucesso e passado pelo schema.
	 */
	public static function handle_import() {
		self::guard( 'ebr_ads_import' );

		if ( ! isset( $_FILES['ebr_import'] ) || ! is_array( $_FILES['ebr_import'] ) ) {
			self::redirect( 'nofile', 'imexport' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validado campo a campo abaixo.
		$file = $_FILES['ebr_import'];

		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			self::redirect( 'uploaderror', 'imexport' );
		}

		if ( ! isset( $file['size'] ) || (int) $file['size'] > MB_IN_BYTES ) {
			self::redirect( 'toobig', 'imexport' );
		}

		// Verifica a extensão real pelo nome sanitizado, não pelo Content-Type
		// enviado pelo cliente.
		$name = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		if ( 'json' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			self::redirect( 'badtype', 'imexport' );
		}

		$tmp = isset( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			self::redirect( 'uploaderror', 'imexport' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- caminho temporário do PHP, não é URL.
		$raw = file_get_contents( $tmp );
		if ( false === $raw ) {
			self::redirect( 'uploaderror', 'imexport' );
		}

		$decoded = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			// Aborta. No QUADS, este caminho gravava null e apagava tudo.
			self::redirect( 'badjson', 'imexport' );
		}

		$existing = EBR_Ads_Store::get();

		// O import respeita a capability de código: um editor sem
		// ebr_manage_ad_code não injeta JavaScript via arquivo.
		$clean = EBR_Ads_Schema::sanitize_settings( $decoded, $existing, EBR_Ads_Caps::can_manage_code() );

		EBR_Ads_Store::save( $clean );

		self::redirect( 'imported', 'imexport' );
	}

	/**
	 * Reexecuta a importação a partir do QUADS.
	 */
	public static function handle_reimport() {
		self::guard( 'ebr_ads_reimport' );

		// Só quem pode gravar código pode trazer código do QUADS.
		if ( ! EBR_Ads_Caps::can_manage_code() ) {
			wp_die(
				esc_html__( 'A reimportação traz código de anúncio e exige a permissão ebr_manage_ad_code.', 'ebr-ads' ),
				'',
				array( 'response' => 403 )
			);
		}

		EBR_Ads_Importer::maybe_import( true );

		self::redirect( 'reimported', 'imexport' );
	}
}
