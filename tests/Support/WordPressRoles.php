<?php
/**
 * WordPress's own roles, as populate_roles() creates them, for tests of the stored role map.
 *
 * @package BackupsForRestic\Tests
 */

declare( strict_types=1 );

namespace BackupsForRestic\Tests\Support;

/** The five roles of a fresh WordPress and the values tests derive from them. */
final class WordPressRoles {

	/** What a database restore needs of its administrator besides the backup capability. */
	public const array DATABASE = [ 'manage_options', 'edit_users', 'promote_users', 'update_core' ];

	/**
	 * The role map of a fresh WordPress, as get_option( 'wp_user_roles' ) returns it.
	 *
	 * @return array<string,array{name:string,capabilities:array<string,bool>}>
	 */
	public static function defaults(): array {
		$subscriber  = [ 'read', 'level_0' ];
		$contributor = [ ...$subscriber, 'edit_posts', 'level_1', 'delete_posts' ];
		$author      = [ ...$contributor, 'upload_files', 'edit_published_posts', 'publish_posts', 'level_2', 'delete_published_posts' ];
		$editor      = [ ...$author, 'moderate_comments', 'manage_categories', 'manage_links', 'unfiltered_html', 'edit_others_posts', 'edit_pages', 'level_7', 'level_6', 'level_5', 'level_4', 'level_3', 'edit_others_pages', 'edit_published_pages', 'publish_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'delete_others_posts', 'delete_private_posts', 'edit_private_posts', 'read_private_posts', 'delete_private_pages', 'edit_private_pages', 'read_private_pages' ];
		$admin       = [ ...$editor, 'switch_themes', 'edit_themes', 'activate_plugins', 'edit_plugins', 'edit_users', 'edit_files', 'manage_options', 'import', 'level_10', 'level_9', 'level_8', 'delete_users', 'create_users', 'unfiltered_upload', 'edit_dashboard', 'update_plugins', 'delete_plugins', 'install_plugins', 'update_themes', 'install_themes', 'update_core', 'list_users', 'remove_users', 'promote_users', 'edit_theme_options', 'delete_themes', 'export' ];
		$role        = static fn ( string $name, array $capabilities ): array => [
			'name'         => $name,
			'capabilities' => array_fill_keys( $capabilities, true ),
		];
		return [
			'administrator' => $role( 'Administrator', $admin ),
			'editor'        => $role( 'Editor', $editor ),
			'author'        => $role( 'Author', $author ),
			'contributor'   => $role( 'Contributor', $contributor ),
			'subscriber'    => $role( 'Subscriber', $subscriber ),
		];
	}

	/**
	 * The default roles with capabilities added to one role, as WP_Role::add_cap() stores them.
	 *
	 * @param string       $role Role name.
	 * @param list<string> $capabilities Capabilities granted to it.
	 * @return array<string,array{name:string,capabilities:array<string,bool>}>
	 */
	public static function with( string $role, array $capabilities ): array {
		$roles = self::defaults();
		foreach ( $capabilities as $capability ) {
			$roles[ $role ]['capabilities'][ $capability ] = true;
		}
		return $roles;
	}
}
