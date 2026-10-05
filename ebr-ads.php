<?php
/**
 * Plugin Name: EBR Ads
 * Plugin URI:  https://github.com/cadastrux/ebr-ads
 * Update URI:  https://github.com/cadastrux/ebr-ads
 * Description: Gerenciador de anúncios enxuto: inserção automática por posição, shortcode, widget e condições de exibição. Sem tracking, sem endpoints públicos. Atualizações via tags do GitHub.
 * Version:     1.2.3
 * Author:      EBR Network
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ebr-ads
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package EBR_Ads
 */

defined( 'ABSPATH' ) || exit;

// Precisa bater com o cabeçalho "Version" acima e com o tag do GitHub (v1.2.3).
define( 'EBR_ADS_VERSION', '1.2.3' );
define( 'EBR_ADS_FILE', __FILE__ );
define( 'EBR_ADS_DIR', plugin_dir_path( __FILE__ ) );
define( 'EBR_ADS_URL', plugin_dir_url( __FILE__ ) );

/**
 * Chave da option única do plugin.
 *
 * Tudo — anúncios, posições, visibilidade — vive aqui, normalizado e sanitizado
 * pelo schema em class-ebr-ads-schema.php. Nunca gravamos nada nesta option sem
 * passar pelo sanitizador.
 */
define( 'EBR_ADS_OPTION', 'ebr_ads_settings' );

require_once EBR_ADS_DIR . 'includes/class-ebr-ads-caps.php';
require_once EBR_ADS_DIR . 'includes/class-ebr-ads-schema.php';
require_once EBR_ADS_DIR . 'includes/class-ebr-ads-store.php';
require_once EBR_ADS_DIR . 'includes/class-ebr-ads-importer.php';
require_once EBR_ADS_DIR . 'includes/class-ebr-ads-conditions.php';
require_once EBR_ADS_DIR . 'includes/class-ebr-ads-renderer.php';
require_once EBR_ADS_DIR . 'includes/class-ebr-ads-injector.php';
require_once EBR_ADS_DIR . 'includes/class-ebr-ads-page-injector.php';
require_once EBR_ADS_DIR . 'includes/class-ebr-ads-shortcode.php';
require_once EBR_ADS_DIR . 'includes/class-ebr-ads-widget.php';
require_once EBR_ADS_DIR . 'includes/class-ebr-ads-editor.php';
require_once EBR_ADS_DIR . 'includes/class-ebr-ads-admin.php';
require_once EBR_ADS_DIR . 'includes/class-ebr-ads-updater.php';

/**
 * Ativação em um único site: capabilities + importação única do QUADS.
 */
function ebr_ads_activate_site() {
	EBR_Ads_Caps::add_caps();
	EBR_Ads_Importer::maybe_import();
}

/**
 * Ativação.
 *
 * Em multisite com ativação em rede, register_activation_hook dispara UMA vez,
 * no contexto do site atual. Sem o laço abaixo, os demais sites da rede ficariam
 * sem capabilities e sem a importação do QUADS — o plugin simplesmente não
 * apareceria para os administradores deles.
 *
 * @param bool $network_wide Ativado para a rede inteira.
 */
function ebr_ads_activate( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
			switch_to_blog( $site_id );
			ebr_ads_activate_site();
			restore_current_blog();
		}
		return;
	}

	ebr_ads_activate_site();
}
register_activation_hook( __FILE__, 'ebr_ads_activate' );

/**
 * Sites criados depois da ativação em rede também precisam ser preparados.
 *
 * @param WP_Site $site Site recém-criado.
 */
function ebr_ads_new_site( $site ) {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! is_plugin_active_for_network( plugin_basename( EBR_ADS_FILE ) ) ) {
		return;
	}

	switch_to_blog( $site->id );
	ebr_ads_activate_site();
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'ebr_ads_new_site', 100 );

/*
 * Não há register_deactivation_hook.
 *
 * A versão anterior removia as capabilities ao desativar, o que destruía uma
 * decisão do administrador: quem tivesse concedido ebr_manage_ads a um Editor
 * perdia a concessão em qualquer desativar/reativar. Desativação precisa ser
 * não-destrutiva. As capabilities são removidas em uninstall.php, junto com o
 * resto dos dados do plugin.
 */

/**
 * Boot.
 */
function ebr_ads_init() {
	EBR_Ads_Injector::init();
	EBR_Ads_Page_Injector::init();
	EBR_Ads_Shortcode::init();
	EBR_Ads_Widget::init();

	// Fora do is_admin(): o core também verifica atualizações pelo WP-Cron.
	EBR_Ads_Updater::init();

	if ( is_admin() ) {
		EBR_Ads_Admin::init();
		EBR_Ads_Editor::init();
	}
}
add_action( 'plugins_loaded', 'ebr_ads_init' );
