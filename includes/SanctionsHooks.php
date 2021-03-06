<?php

use Flow\Model\UUID;
use Flow\Exception\InvalidInputException;

class SanctionsHooks {
	/**
	 * Create tables in the database
	 *
	 * @param DatabaseUpdater|null $updater
	 * @throws MWException
	 * @return bool
	 */
	public static function onLoadExtensionSchemaUpdates( $updater = null ) {
		$dir = __DIR__;

		if ( $updater->getDB()->getType() == 'mysql' ) {
			$updater->addExtensionUpdate(
				[ 'addTable', 'sanctions',
				"$dir/../sql/sanctions.tables.sql", true ]
			);
		} // @todo else

		require_once "$dir/../maintenance/SanctionsCreateTemplates.php";
		$updater->addPostDatabaseUpdateMaintenance( 'SanctionsCreateTemplates' );

		return true;
	}

	/**
	 * Abort notifications regarding occupied pages coming from the RecentChange class.
	 * Flow has its own notifications through Echo.
	 *
	 * Also don't notify for actions made by Sanction bot.
	 *
	 * Copied from
	 * https://github.com/wikimedia/mediawiki-extensions-Flow/blob/de0b9ad/Hooks.php#L963-L996
	 *
	 * @param User $editor
	 * @param Title $title
	 * @return bool false to abort email notification
	 */
	public static function onAbortEmailNotification( $editor, $title ) {
		if ( $title->getContentModel() === CONTENT_MODEL_FLOW_BOARD ) {
			// Since we are aborting the notification we need to manually update the watchlist
			$config = RequestContext::getMain()->getConfig();
			if ( $config->get( 'EnotifWatchlist' ) || $config->get( 'ShowUpdatedMarker' ) ) {
				\MediaWiki\MediaWikiServices::getInstance()->getWatchedItemStore()->updateNotificationTimestamp(
					$editor,
					$title,
					wfTimestampNow()
				);
			}
			return false;
		}

		if ( !$editor instanceof User ) {
			return true;
		}

		if ( self::isSanctionBot( $editor ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Suppress all Echo notifications generated by Sanction bot
	 *
	 * Copied from
	 * https://github.com/wikimedia/mediawiki-extensions-Flow/blob/de0b9ad/Hooks.php#L1018-L1034
	 *
	 * @param EchoEvent $event
	 * @return bool
	 */
	public static function onBeforeEchoEventInsert( EchoEvent $event ) {
		$agent = $event->getAgent();

		if ( $agent === null ) {
			return true;
		}

		if ( self::isSanctionBot( $agent ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @param User $user
	 * @return bool
	 */
	private static function isSanctionBot( User $user ) {
		return $user->getName() === wfMessage( 'sanctions-bot-name' )->inContentLanguage()->text();
	}

	/**
	 * @param OutputPage $out
	 * @return bool
	 */
	public static function onFlowAddModules( OutputPage $out ) {
		$title = $out->getTitle();
		$specialSanctionTitle = SpecialPage::getTitleFor( 'Sanctions' ); // Special:Sanctions
		$discussionPageName = wfMessage( 'sanctions-discussion-page-name' )
			->inContentLanguage()->text(); // ProjectTalk:foobar

		if ( $title == null ) {
			return true;
		}

		// The Flow board for sanctions
		if ( $title->equals( Title::newFromText( $discussionPageName ) ) ) {
			// Flow does not support redirection, so implement it.
			// See https://phabricator.wikimedia.org/T102300
			$request = RequestContext::getMain()->getRequest();
			$redirect = $request->getVal( 'redirect' );
			if ( !$redirect || $redirect !== 'no' ) {
				$out->redirect( $specialSanctionTitle->getLocalURL() );
			}

			$out->addModules( 'ext.sanctions.flow-board' );

			return true;
		}

		// Each Flow topic
		$uuid = null;
		try {
			$uuid = UUID::create( strtolower( $title->getText() ) );
		} catch ( InvalidInputException $e ) {
			return true;
		}

		// Do nothing when UUID is invalid
		if ( !$uuid ) {
			return true;
		}

		// Do nothing when the topic is not about sanction
		$sanction = Sanction::newFromUUID( $uuid );
		if ( $sanction === false ) {
			return true;
		}

		$out->addModules( 'ext.sanctions.flow-topic' );

		if ( !$sanction->isExpired() ) {
			$sanction->checkNewVotes();
		}
		// else @todo mark as expired

		return true;
	}

	/**
	 * export static key and id to JavaScript
	 * @param array &$vars Array of variables to be added into the output of the startup module.
	 * @return true
	 */
	public static function onResourceLoaderGetConfigVars( &$vars ) {
		$vars['wgSanctionsAgreeTemplate'] = wfMessage( 'sanctions-agree-template-title' )
			->inContentLanguage()->text();
		$vars['wgSanctionsDisagreeTemplate'] = wfMessage( 'sanctions-disagree-template-title' )
			->inContentLanguage()->text();
		$vars['wgSanctionsInsultingNameTopicTitle'] = wfMessage( 'sanctions-type-insulting-name' )
			->inContentLanguage()->text();
		$vars['wgSanctionsMaxBlockPeriod'] = (int)wfMessage( 'sanctions-max-block-period' )
			->inContentLanguage()->text();

		return true;
	}

	// (talk|contribs)
	public static function onUserToolLinksEdit( $userId, $userText, &$items ) {
		global $wgUser;
		if ( $wgUser == null || !SanctionsUtils::hasVoteRight( $wgUser ) ) {
			return true;
		}

		$items[] = Linker::link(
			SpecialPage::getTitleFor( 'Sanctions', $userText ),
			wfMessage( 'sanctions-link-on-user-tool' )->text()
		);
		return true;
	}

	/**
	 * Tools shown as (edit) (undo) (thank)
	 * @param Revision $newRev object of the "new" revision
	 * @param array &$links Array of HTML links
	 * @param Revision $oldRev object of the "old" revision (may be null)
	 * @return bool
	 */
	public static function onDiffRevisionTools( Revision $newRev, &$links, $oldRev ) {
		global $wgUser;
		if ( $wgUser == null || !SanctionsUtils::hasVoteRight( $wgUser ) ) {
			return true;
		}

		$ids = '';
		if ( $oldRev != null ) {
			$ids .= $oldRev->getId() . '/';
		}
		$ids .= $newRev->getId();

		$titleText = $newRev->getUserText() . '/' . $ids;
		$links[] = Linker::link(
			SpecialPage::getTitleFor( 'Sanctions', $titleText ),
			wfMessage( 'sanctions-link-on-diff' )->text()
	);

		return true;
	}

	/**
	 * @param Revision $rev Revision object
	 * @param array &$links Array of HTML links
	 * @return bool
	 */
	public static function onHistoryRevisionTools( $rev, &$links ) {
		global $wgUser;

		if ( $wgUser == null || !SanctionsUtils::hasVoteRight( $wgUser ) ) {
			return true;
		}

		$titleText = $rev->getUserText() . '/' . $rev->getId();
		$links[] = Linker::link(
			SpecialPage::getTitleFor( 'Sanctions', $titleText ),
			wfMessage( 'sanctions-link-on-history' )->text()
		);

		return true;
	}

	/**
	 * @param BaseTemplate $baseTemplate The BaseTemplate base skin template.
	 * @param array &$toolbox An array of toolbox items.
	 */
	public static function onBaseTemplateToolbox( BaseTemplate $baseTemplate, array &$toolbox ) {
		$user = $baseTemplate->getSkin()->getRelevantUser();
		if ( $user ) {
			$rootUser = $user->getName();

			$toolbox = wfArrayInsertAfter(
				$toolbox,
				[ 'sanctions' => [
					'text' => wfMessage( 'sanctions-link-on-user-page' )->text(),
					'href' => Skin::makeSpecialUrlSubpage( 'Sanctions', $rootUser ),
					'id' => 't-sanctions'
				] ],
				isset( $toolbox['blockip'] ) ? 'blockip' : 'log'
			);
		}
	}

	/**
	 * @param int $id - User identifier
	 * @param Title $title - User page title
	 * @param array &$tools - Array of tool links
	 * @param SpecialPage $sp - The SpecialPage object
	 */
	public static function onContributionsToolLinks( $id, $title, &$tools, $sp ) {
		$tools['sanctions'] = $sp->getLinkRenderer()->makeKnownLink(
				SpecialPage::getTitleFor( 'Sanctions', User::newFromId( $id ) ),
				wfMessage( 'sanctions-link-on-user-contributes' )->text()
			);
	}
}
