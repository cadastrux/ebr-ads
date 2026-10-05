<?php
/**
 * Desinstalação.
 *
 * §44 do documento de auditoria: remover apenas os dados próprios, e nunca por
 * padrão genérico. Aqui são chaves nomeadas explicitamente — não há
 * DELETE ... LIKE 'ebr%', não há varredura de postmeta, não há DROP TABLE
 * (o plugin não cria tabelas).
 *
 * @package EBR_Ads
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'ebr_ads_settings' );
delete_option( 'ebr_ads_import_done' );
delete_site_transient( 'ebr_ads_github_tag' );
delete_site_transient( 'ebr_ads_github_release' ); // Cache da 1.1.x–1.2.0.

// Multisite: a mesma limpeza, site a site. Sem tocar em nada fora da rede.
if ( is_multisite() ) {
	$sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );

	foreach ( $sites as $site_id ) {
		switch_to_blog( $site_id );
		delete_option( 'ebr_ads_settings' );
		delete_option( 'ebr_ads_import_done' );
		restore_current_blog();
	}
}

// Este é o ÚNICO lugar onde as capabilities são removidas. A desativação não as
// toca, para não destruir concessões feitas pelo administrador a outras roles.
// Um plugin removido, porém, não deve deixar capabilities órfãs.
foreach ( wp_roles()->role_objects as $role ) {
	$role->remove_cap( 'ebr_manage_ads' );
	$role->remove_cap( 'ebr_manage_ad_code' );
}

/*
 * Deliberadamente NÃO removemos:
 *   - as post metas _ebr_ads_disabled e _ebr_ads_hide (são anotação editorial
 *     do usuário);
 *   - qualquer dado do QUADS (wp_options[quads_settings], CPT quads-ads).
 *
 * O importador só lê o QUADS. Desinstalar este plugin nunca pode danificar o
 * plugin do qual ele importou.
 */
