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

/**
 * Bookkeeping for Diplomacy Lab.
 *
 * Diplomacy Lab keeps two small tables of its own and touches nothing else:
 *
 *  - wD_LabGames stores, for each Lab board, the user-visible name and the snapshot of the
 *    position as it stood immediately before the last RESOLVE. That snapshot is what RESET
 *    restores, which is why RESET returns to exactly the position you built rather than to
 *    whatever webDiplomacy happens to have in its turn archives.
 *  - wD_LabPositions stores named positions the user has saved, as JSON.
 *
 * @package Base
 * @subpackage Lab
 */
class libLab
{
	/**
	 * Whether the Lab's tables have been created, cached for the request.
	 * @var bool
	 */
	private static $tablesReady = false;

	/**
	 * Create the Lab's tables if they are not there yet.
	 *
	 * Diplomacy Lab ships its schema in install/DiplomacyLab/lab.sql and in the full install
	 * script, but a fork will often be running against a database that predates it, so the tables
	 * are created on demand as well. Both statements are IF NOT EXISTS, so this is a no-op once
	 * the tables exist.
	 */
	public static function ensureTables()
	{
		global $DB;

		if( self::$tablesReady ) return;

		// Creating a table implicitly commits the request's transaction in MySQL, so the tables
		// are only ever created when they are actually missing, not checked into existence on
		// every request.
		$tabl = $DB->sql_tabl("SHOW TABLES LIKE 'wD_LabGames'");
		if( $DB->tabl_row($tabl) )
		{
			self::$tablesReady = true;
			return;
		}

		$DB->sql_put("CREATE TABLE IF NOT EXISTS `wD_LabGames` (
			`gameID` mediumint(8) unsigned NOT NULL,
			`userID` mediumint(8) unsigned NOT NULL,
			`name` varchar(120) NOT NULL DEFAULT '',
			`snapshotJSON` mediumtext DEFAULT NULL,
			`timeCreated` int(10) unsigned NOT NULL DEFAULT 0,
			`timeLastUsed` int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (`gameID`),
			KEY `userID` (`userID`,`timeLastUsed`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8");

		$DB->sql_put("CREATE TABLE IF NOT EXISTS `wD_LabPositions` (
			`id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
			`userID` mediumint(8) unsigned NOT NULL,
			`name` varchar(120) NOT NULL DEFAULT '',
			`variantID` tinyint(3) unsigned NOT NULL DEFAULT 1,
			`positionJSON` mediumtext NOT NULL,
			`timeSaved` int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (`id`),
			KEY `userID` (`userID`,`timeSaved`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8");

		$DB->sql_put("COMMIT");

		self::$tablesReady = true;
	}

	// --- Lab boards ---------------------------------------------------------------------------

	/**
	 * Register a game as a Lab board belonging to the current user.
	 *
	 * @param int $gameID
	 * @param string $name the user-visible name
	 */
	public static function registerGame($gameID, $name)
	{
		global $DB, $User;

		self::ensureTables();

		$gameID = (int)$gameID;
		$name = $DB->escape(self::trimName($name));

		$DB->sql_put("INSERT INTO wD_LabGames ( gameID, userID, name, timeCreated, timeLastUsed )
			VALUES ( ".$gameID.", ".(int)$User->id.", '".$name."', ".time().", ".time()." )
			ON DUPLICATE KEY UPDATE name = '".$name."', timeLastUsed = ".time());

		$DB->sql_put("COMMIT");
	}

	/**
	 * Whether a game is a Lab board.
	 *
	 * @param int $gameID
	 * @return bool
	 */
	public static function isLabGame($gameID)
	{
		global $DB;

		self::ensureTables();

		list($count) = $DB->sql_row("SELECT COUNT(*) FROM wD_LabGames WHERE gameID = ".(int)$gameID);

		return ($count > 0);
	}

	/**
	 * The Lab record for a game, or false.
	 *
	 * @param int $gameID
	 * @return array|false
	 */
	public static function getGame($gameID)
	{
		global $DB;

		self::ensureTables();

		$tabl = $DB->sql_tabl("SELECT gameID, userID, name, snapshotJSON, timeCreated, timeLastUsed
			FROM wD_LabGames WHERE gameID = ".(int)$gameID);

		$row = $DB->tabl_hash($tabl);

		return $row ? $row : false;
	}

	/**
	 * Rename a Lab board.
	 *
	 * @param int $gameID
	 * @param string $name
	 */
	public static function renameGame($gameID, $name)
	{
		global $DB;

		self::ensureTables();

		$DB->sql_put("UPDATE wD_LabGames SET name = '".$DB->escape(self::trimName($name))."'
			WHERE gameID = ".(int)$gameID);
		$DB->sql_put("COMMIT");
	}

	/**
	 * Remove a Lab board's record. The underlying game is erased separately.
	 *
	 * @param int $gameID
	 */
	public static function forgetGame($gameID)
	{
		global $DB;

		self::ensureTables();

		$DB->sql_put("DELETE FROM wD_LabGames WHERE gameID = ".(int)$gameID);
		$DB->sql_put("COMMIT");
	}

	/**
	 * The current user's Lab boards, most recently used first.
	 *
	 * @return array
	 */
	public static function listGames()
	{
		global $DB, $User;

		self::ensureTables();

		$games = array();

		$tabl = $DB->sql_tabl("SELECT l.gameID, l.name, l.timeLastUsed, g.turn, g.phase, g.variantID
			FROM wD_LabGames l
			INNER JOIN wD_Games g ON ( g.id = l.gameID )
			WHERE l.userID = ".(int)$User->id."
			ORDER BY l.timeLastUsed DESC, l.gameID DESC");

		while( $row = $DB->tabl_hash($tabl) )
			$games[] = $row;

		return $games;
	}

	/**
	 * Record that a board was just used, so it sorts to the top of the Lab list.
	 *
	 * @param int $gameID
	 */
	public static function touchGame($gameID)
	{
		global $DB;

		self::ensureTables();

		$DB->sql_put("UPDATE wD_LabGames SET timeLastUsed = ".time()." WHERE gameID = ".(int)$gameID);
		$DB->sql_put("COMMIT");
	}

	// --- Reset snapshots ----------------------------------------------------------------------

	/**
	 * Store the position a board is in, so RESET can bring it back.
	 *
	 * This is taken immediately before adjudicating, which is what makes the Lab loop fast: you
	 * resolve, look at the result, reset back to exactly the position you had, change one order
	 * and resolve again.
	 *
	 * @param int $gameID
	 * @param LabPosition $Position
	 */
	public static function saveSnapshot($gameID, LabPosition $Position)
	{
		global $DB;

		self::ensureTables();

		$DB->sql_put("UPDATE wD_LabGames SET snapshotJSON = '".$DB->escape($Position->toJSON())."'
			WHERE gameID = ".(int)$gameID);
		$DB->sql_put("COMMIT");
	}

	/**
	 * The stored snapshot for a board, or false if it has never been resolved.
	 *
	 * @param int $gameID
	 * @return LabPosition|false
	 */
	public static function loadSnapshot($gameID)
	{
		global $DB;

		self::ensureTables();

		$row = $DB->sql_row("SELECT snapshotJSON FROM wD_LabGames WHERE gameID = ".(int)$gameID);

		if( $row === false ) return false;

		$snapshotJSON = $row[0];

		if( is_null($snapshotJSON) || $snapshotJSON === '' ) return false;

		require_once(l_r('objects/labPosition.php'));

		return LabPosition::fromJSON($snapshotJSON);
	}

	// --- Saved positions ----------------------------------------------------------------------

	/**
	 * Save a named position for the current user.
	 *
	 * @param LabPosition $Position
	 * @param string $name
	 * @return int the saved position's ID
	 */
	public static function savePosition(LabPosition $Position, $name)
	{
		global $DB, $User;

		self::ensureTables();

		$name = self::trimName($name);
		if( $name === '' ) $name = 'Position '.date('Y-m-d H:i');

		$Position->name = $name;

		$DB->sql_put("INSERT INTO wD_LabPositions ( userID, name, variantID, positionJSON, timeSaved )
			VALUES (
				".(int)$User->id.",
				'".$DB->escape($name)."',
				".(int)$Position->Variant->id.",
				'".$DB->escape($Position->toJSON())."',
				".time()."
			)");

		$id = $DB->last_inserted();

		$DB->sql_put("COMMIT");

		return $id;
	}

	/**
	 * A saved position belonging to the current user.
	 *
	 * @param int $positionID
	 * @return LabPosition
	 * @throws Exception
	 */
	public static function loadPosition($positionID)
	{
		global $DB, $User;

		self::ensureTables();

		$row = $DB->sql_row("SELECT positionJSON FROM wD_LabPositions
			WHERE id = ".(int)$positionID." AND userID = ".(int)$User->id);

		if( $row === false || !$row[0] )
			throw new Exception(l_t("That saved position could not be found."));

		$positionJSON = $row[0];

		require_once(l_r('objects/labPosition.php'));

		return LabPosition::fromJSON($positionJSON);
	}

	/**
	 * Delete a saved position belonging to the current user.
	 *
	 * @param int $positionID
	 */
	public static function deletePosition($positionID)
	{
		global $DB, $User;

		self::ensureTables();

		$DB->sql_put("DELETE FROM wD_LabPositions
			WHERE id = ".(int)$positionID." AND userID = ".(int)$User->id);
		$DB->sql_put("COMMIT");
	}

	/**
	 * The current user's saved positions, newest first.
	 *
	 * @return array
	 */
	public static function listPositions()
	{
		global $DB, $User;

		self::ensureTables();

		$positions = array();

		$tabl = $DB->sql_tabl("SELECT id, name, variantID, timeSaved FROM wD_LabPositions
			WHERE userID = ".(int)$User->id."
			ORDER BY timeSaved DESC, id DESC");

		while( $row = $DB->tabl_hash($tabl) )
			$positions[] = $row;

		return $positions;
	}

	// --- Helpers ------------------------------------------------------------------------------

	/**
	 * Clean up a user-supplied name so it fits the column and contains no markup.
	 *
	 * @param string $name
	 * @return string
	 */
	public static function trimName($name)
	{
		$name = trim(strip_tags((string)$name));
		$name = preg_replace('/\s+/', ' ', $name);

		return mb_substr($name, 0, 120);
	}

	/**
	 * The board URL for a Lab game.
	 *
	 * The Lab uses webDiplomacy's original board, with view=dropDown so that the classic order
	 * interface is used. That interface already loads every country's orders in a sandbox game
	 * and readies all of them at once, and unlike the React board it does not have to be built
	 * with npm before it will run.
	 *
	 * @param int $gameID
	 * @return string
	 */
	public static function boardURL($gameID)
	{
		return 'board.php?gameID='.(int)$gameID.'&view=dropDown';
	}
}
