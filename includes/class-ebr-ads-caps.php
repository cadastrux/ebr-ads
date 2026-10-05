<?php
/**
 * Capabilities.
 *
 * Auditoria ADS-SEC-004: o QUADS verificava NOMES DE ROLE contra um mapa em
 * wp_options, com um único portão para 33 rotas. Aqui usamos capabilities reais
 * do WordPress, verificadas individualmente, e em duas camadas separadas:
 *
 *   ebr_manage_ads      → gerenciar anúncios, posições e visibilidade
 *   ebr_manage_ad_code  → gravar HTML/JavaScript bruto no campo "code"
 *
 * A separação existe porque injetar JavaScript é uma capacidade qualitativamente
 * diferente de gerenciar campanhas (§28 do documento de auditoria: menor
 * privilégio possível). Quem tem apenas ebr_manage_ads edita rótulo, alinhamento,
 * margem e posição — mas não consegue alterar o código do anúncio.
 *
 * @package EBR_Ads
 */

defined( 'ABSPATH' ) || exit;

class EBR_Ads_Caps {

	const MANAGE      = 'ebr_manage_ads';
	const MANAGE_CODE = 'ebr_manage_ad_code';

	/**
	 * Concede as capabilities na ativação.
	 *
	 * Por padrão só o administrador recebe as duas. Conceder ebr_manage_ads a
	 * outra role é uma decisão explícita do site, feita com add_cap() — não há
	 * mapa de roles em wp_options que um import malicioso possa reescrever
	 * (era exatamente esse o vetor do ADS-SEC-002).
	 */
	public static function add_caps() {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::MANAGE );
			$admin->add_cap( self::MANAGE_CODE );
		}
	}

	/**
	 * Remove as capabilities de todas as roles.
	 */
	public static function remove_caps() {
		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( self::MANAGE );
			$role->remove_cap( self::MANAGE_CODE );
		}
	}

	/**
	 * O usuário atual pode gerenciar anúncios?
	 */
	public static function can_manage() {
		return current_user_can( self::MANAGE );
	}

	/**
	 * O usuário atual pode gravar código bruto?
	 *
	 * Note que NÃO basta ter ebr_manage_ads. É uma checagem independente,
	 * usada pelo sanitizador para decidir se preserva ou aceita o campo `code`.
	 */
	public static function can_manage_code() {
		return current_user_can( self::MANAGE_CODE );
	}
}
