<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\WikiOasisSafety\Pii;

use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use UserProfilePage;

final class PiiTables {

	/**
	 * @param array{userId: int, actorId: int, oldName: string, newName: string, oldTitleKey: string, renameLogKey: string} $for
	 * @return array<string, list<array{where: array<string, mixed>}>>
	 */
	public static function deletions( array $for ): array {
		$defaults = [
			'block' => [
				[ 'where' => [ 'bl_by_actor' => $for['actorId'] ] ],
			],
			'block_target' => [
				[ 'where' => [ 'bt_user' => $for['userId'] ] ],
			],
			'user_groups' => [
				[ 'where' => [ 'ug_user' => $for['userId'] ] ],
			],
			'logging' => [
				[
					'where' => [
						'log_type' => 'gblrename',
						'log_action' => 'rename',
						'log_title' => $for['renameLogKey'],
					],
				],
				[
					'where' => [
						'log_type' => 'renameuser',
						'log_action' => 'renameuser',
						'log_title' => $for['oldTitleKey'],
					],
				],
			],
			'recentchanges' => [
				[
					'where' => [
						'rc_log_type' => 'gblrename',
						'rc_log_action' => 'rename',
						'rc_title' => $for['renameLogKey'],
					],
				],
				[
					'where' => [
						'rc_log_type' => 'renameuser',
						'rc_log_action' => 'renameuser',
						'rc_title' => $for['oldTitleKey'],
					],
				],
			],
			'cu_changes' => [
				[ 'where' => [ 'cuc_actor' => $for['actorId'] ] ],
			],
			'cu_log_event' => [
				[ 'where' => [ 'cule_actor' => $for['actorId'] ] ],
			],
			'cu_private_event' => [
				[ 'where' => [ 'cupe_actor' => $for['actorId'] ] ],
			],
			'cu_log' => [
				[
					'where' => [
						'cul_target_id' => $for['userId'],
						'cul_type' => [ 'useredits', 'userips' ],
					],
				],
				[ 'where' => [ 'cul_actor' => $for['actorId'] ] ],
			],

			'user_board' => [
				[ 'where' => [ 'ub_actor' => $for['actorId'] ] ],
				[ 'where' => [ 'ub_actor_from' => $for['actorId'] ] ],
			],
			'user_profile' => [
				[ 'where' => [ 'up_actor' => $for['actorId'] ] ],
			],
		];

		return self::merge( $defaults, 'WikiOasisSafetyPiiDeletions' );
	}

	/**
	 * @param array{userId: int, actorId: int, oldName: string, newName: string,
	 *              oldTitleKey: string, renameLogKey: string} $for
	 * @return array<string, list<array{fields: array<string, mixed>, where: array<string, mixed>}>>
	 */
	public static function updates( array $for ): array {
		$defaults = [
			'recentchanges' => [
				[
					'fields' => [ 'rc_ip' => '0.0.0.0' ],
					'where' => [ 'rc_actor' => $for['actorId'] ],
				],
			],
			'abuse_filter_log' => [
				[
					'fields' => [ 'afl_user_text' => $for['newName'] ],
					'where' => [ 'afl_user_text' => $for['oldName'] ],
				],
			],
			'echo_event' => [
				[
					'fields' => [ 'event_agent_ip' => null ],
					'where' => [ 'event_agent_id' => $for['userId'] ],
				],
			],
			'moderation' => [
				[
					'fields' => [
						'mod_header_xff' => '',
						'mod_header_ua' => '',
						'mod_ip' => '0.0.0.0',
					],
					'where' => [ 'mod_user' => $for['userId'] ],
				],
				[
					'fields' => [
						'mod_header_xff' => '',
						'mod_header_ua' => '',
						'mod_ip' => '0.0.0.0',
						'mod_user_text' => $for['newName'],
					],
					'where' => [ 'mod_user_text' => $for['oldName'] ],
				],
			],
			'ajaxpoll_vote' => [
				[
					'fields' => [ 'poll_ip' => '0.0.0.0' ],
					'where' => [ 'poll_actor' => $for['actorId'] ],
				],
			],
			'Vote' => [
				[
					'fields' => [ 'vote_ip' => '0.0.0.0' ],
					'where' => [ 'vote_actor' => $for['actorId'] ],
				],
			],
		];

		return self::merge( $defaults, 'WikiOasisSafetyPiiUpdates' );
	}

	/**
	 * @return list<int>
	 */
	public static function userNamespaces(): array {
		$namespaces = [ NS_USER, NS_USER_TALK ];

		if ( class_exists( UserProfilePage::class ) ) {
			array_push(
				$namespaces,
				NS_USER_WIKI, NS_USER_WIKI_TALK, NS_USER_PROFILE, NS_USER_PROFILE_TALK
			);
		}

		$registry = ExtensionRegistry::getInstance();

		if ( $registry->isLoaded( 'SimpleBlogPage' ) ) {
			array_push( $namespaces, NS_USER_BLOG, NS_USER_BLOG_TALK );
		}

		if ( $registry->isLoaded( 'BlogPage' ) ) {
			array_push( $namespaces, 500, 501 );
		}

		$extra = MediaWikiServices::getInstance()->getMainConfig()
			->get( 'WikiOasisSafetyPiiNamespaces' );

		return array_values( array_unique( array_merge( $namespaces, (array)$extra ) ) );
	}

	/**
	 * @param array<string, list<array<string, mixed>>> $defaults
	 * @return array<string, list<array<string, mixed>>>
	 */
	private static function merge( array $defaults, string $setting ): array {
		$configured = MediaWikiServices::getInstance()->getMainConfig()->get( $setting );

		if ( !is_array( $configured ) ) {
			return $defaults;
		}

		foreach ( $configured as $table => $operations ) {
			if ( !is_array( $operations ) ) {
				continue;
			}

			$defaults[ $table ] = $operations === []
				? []
				: array_merge( $defaults[ $table ] ?? [], $operations );
		}

		return array_filter( $defaults, static fn ( array $ops ): bool => $ops !== [] );
	}
}
