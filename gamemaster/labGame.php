<?php
/*
    Copyright (C) 2004-2010 Kestas J. Kuliukas

	This file is part of webDiplomacy.

    webDiplomacy is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    webDiplomacy is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with webDiplomacy.  If not, see <http://www.gnu.org/licenses/>.
 */

defined('IN_CODE') or die('This script can not be run by itself.');

require_once(l_r('gamemaster/sandboxGame.php'));
require_once(l_r('objects/labPosition.php'));

/**
 * A Diplomacy Lab game: a sandbox game used as a free tactical analysis board.
 *
 * This class deliberately adds as little as possible on top of webDiplomacy. It does not contain
 * any adjudication logic; RESOLVE just readies every country and calls processGame::process(),
 * so the original adjudicator remains the only source of truth for the rules.
 *
 * The one behaviour it does change is changePhase(), because webDiplomacy's version eliminates
 * countries with no units and ends the game when only one country is left standing (which then
 * deletes every unit on the board). In a lab that is wrong: a position with only France and
 * Germany on the board, or a single power practising against an empty map, must simply keep
 * working. The override reproduces the phase-transition half of the original exactly and skips
 * only the defeat/victory half.
 *
 * @package GameMaster
 * @subpackage Lab
 */
class processLabGame extends processSandboxGame
{
	/**
	 * Create a new, empty Lab game for the current user.
	 *
	 * The underlying game is an ordinary webDiplomacy sandbox game, so everything that already
	 * knows how to handle sandbox games (the board, the order interface, the map renderer) keeps
	 * working with no changes.
	 *
	 * @param int $variantID
	 * @return processLabGame
	 */
	public static function createGame($variantID = 1)
	{
		global $User;

		if( !$User->type['User'] ) throw new Exception("Non-users cannot create Lab positions.");

		// Game names are unique; the name the user sees is stored against the Lab record instead.
		$internalName = 'Lab'.$User->id.'_'.substr(md5(uniqid('', true)), 0, 16);

		$Variant = libVariant::loadFromVariantID($variantID);
		libVariant::setGlobals($Variant);

		$Game = processSandboxGame::newGame($variantID, $internalName);

		$LabGame = new processLabGame($Game->id);
		$GLOBALS['Game'] = $LabGame;

		return $LabGame;
	}

	/**
	 * Load a Lab game by ID, checking that the current user owns it.
	 *
	 * @param int $gameID
	 * @return processLabGame
	 */
	public static function loadGame($gameID)
	{
		global $DB, $User;

		$gameID = (int)$gameID;

		$row = $DB->sql_row("SELECT sandboxCreatedByUserID FROM wD_Games WHERE id = ".$gameID);

		if( $row === false )
			throw new Exception(l_t("Position %s could not be found.", $gameID));

		$sandboxCreatedByUserID = $row[0];

		if( is_null($sandboxCreatedByUserID) )
			throw new Exception(l_t("Game %s is not a Lab position.", $gameID));

		if( (int)$sandboxCreatedByUserID !== (int)$User->id && !$User->type['Moderator'] )
			throw new Exception(l_t("You can only work on Lab positions you created."));

		$Variant = libVariant::loadFromGameID($gameID);
		libVariant::setGlobals($Variant);

		$LabGame = new processLabGame($gameID);
		$GLOBALS['Game'] = $LabGame;

		return $LabGame;
	}

	/**
	 * The position currently on the board.
	 *
	 * @return LabPosition
	 */
	public function getPosition()
	{
		return LabPosition::fromGame($this);
	}

	/**
	 * Replace the board with the given position.
	 *
	 * Any archived turns at or after the position's turn are removed, so that after resetting or
	 * re-editing a position the game's history does not still contain the turns that were undone.
	 *
	 * @param LabPosition $Position
	 */
	public function setPosition(LabPosition $Position)
	{
		global $DB;

		$Position->applyToGame($this);

		$DB->sql_put("DELETE FROM wD_TerrStatusArchive WHERE gameID = ".$this->id." AND turn > ".(int)$Position->turn);
		$DB->sql_put("DELETE FROM wD_MovesArchive WHERE gameID = ".$this->id." AND turn >= ".(int)$Position->turn);

		$this->freezeClock();

		$DB->sql_put("COMMIT");

		$this->load();
		$this->loadMembers();
		Game::wipeCache($this->id);
	}

	/**
	 * Push this board's process time out of the gamemaster's reach.
	 *
	 * Called after anything that changes the board, including adjudication, because
	 * processGame::process() resets the process time to one phase length as it finishes.
	 */
	private function freezeClock()
	{
		global $DB;

		$processTime = time() + LabPosition::CLOCK_FREEZE_SECONDS;

		$DB->sql_put("UPDATE wD_Games
			SET processTime = ".$processTime.",
				processStatus = 'Not-processing',
				pauseTimeRemaining = NULL,
				attempts = 0
			WHERE id = ".$this->id);

		$this->processTime = $processTime;
	}

	/**
	 * Ready every country and adjudicate immediately.
	 *
	 * In a Lab position one person enters every power's orders, so there is nothing to wait for:
	 * this readies all countries that have orders this phase and then hands the game straight to
	 * webDiplomacy's own processGame::process(), instead of leaving a hint for the gamemaster cron
	 * and waiting for it to come round.
	 *
	 * Countries with no orders this phase are left as 'None', which is what webDiplomacy's own
	 * order generator sets and what Members::isReady() expects.
	 */
	public function resolve()
	{
		global $DB;

		if( $this->phase == 'Pre-game' || $this->phase == 'Finished' )
			throw new Exception(l_t("This position is not in a phase that can be resolved."));

		$DB->sql_put("UPDATE wD_Members m
			SET m.orderStatus = IF(
					EXISTS(SELECT 1 FROM wD_Orders o WHERE o.gameID = m.gameID AND o.countryID = m.countryID),
					'Saved,Completed,Ready',
					'None'
				),
				m.orderStatusChanged = UNIX_TIMESTAMP()
			WHERE m.gameID = ".$this->id);

		$DB->sql_put("UPDATE wD_Games SET processStatus = 'Not-processing', attempts = 0 WHERE id = ".$this->id);

		// Reload so the in-memory members carry the order status we just wrote
		$this->load();
		$this->loadMembers();

		$this->process();

		// process() has just reset the phase timer; put it back out of reach
		$this->freezeClock();

		$DB->sql_put("COMMIT");

		$this->load();
		$this->loadMembers();
		Game::wipeCache($this->id);
	}

	/**
	 * Move to a new phase without eliminating countries or ending the game.
	 *
	 * This is processGame::changePhase() with the findSetDefeated()/checkForWinner() step removed.
	 * The phase transition logic itself is unchanged, so Retreats and Builds behave exactly as they
	 * do in a normal game.
	 *
	 * @return bool whether a new turn was started
	 */
	protected function changePhase()
	{
		global $DB;

		// If it's Pre-game make it Diplomacy
		if( $this->phase == 'Pre-game' )
		{
			$this->setPhase('Diplomacy');
			return false; // No TerrStatus to cache -> no need for a new year
		}

		/*
		 * A Lab position is free: powers may hold no units and no supply centers, and a single
		 * power may be the only one on the board. In a real game either situation would eliminate
		 * players and finish the game, which would then wipe the board. Here the position simply
		 * carries on, so the defeat and victory checks are deliberately not run.
		 */

		switch($this->phase)
		{
			case 'Diplomacy':
				list($retreating) = $DB->sql_row("SELECT COUNT(retreatingUnitID)
												FROM wD_TerrStatus WHERE gameID=".$this->id);

				if($retreating)
				{
					$this->setPhase('Retreats');
					return false;
				}

			case 'Retreats':
				/*
				 * If it's autumn and we just came from Diplomacy or Retreats we may
				 * need to make some units.
				 */
				if( 0 != ($this->turn % 2) and $this->Members->checkForUnitSCDifference() )
				{
					$this->setPhase('Builds');
					return false;
				}

			default:
				$this->setPhase('Diplomacy');
				return true; // New turn!
		}
	}

	/**
	 * Erase this Lab board and everything belonging to it.
	 *
	 * processSandboxGame::eraseGame() is deliberately not used: it compares the game's
	 * sandboxCreatedByUserID, which mysqli returns as a string, strictly (!==) against the
	 * integer $User->id, so it rejects even the game's own creator. Ownership has already been
	 * checked by loadGame(), so this goes straight to processGame's own erase.
	 */
	public function deleteGame()
	{
		processGame::eraseGame($this->id, false);
	}

	/**
	 * Create an independent copy of this Lab game, at its current position.
	 *
	 * The copy gets its own units, orders and results, so variations can be explored side by side
	 * from a shared starting position.
	 *
	 * @return processLabGame
	 */
	public function duplicateGame()
	{
		$Position = $this->getPosition();

		$Copy = self::createGame($this->variantID);
		$Copy->setPosition($Position);

		return $Copy;
	}
}
