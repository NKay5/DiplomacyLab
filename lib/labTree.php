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

require_once(l_r('lib/lab.php'));
require_once(l_r('objects/labPosition.php'));
require_once(l_r('gamemaster/labGame.php'));

/**
 * The analysis tree behind Diplomacy Lab.
 *
 * A scenario is a line of play the user is studying. It holds one or more branches; each branch is
 * a sequence of nodes, and each node is one position - a phase, exactly as the engine left it.
 * Adjudicating a node appends its successor to the branch. Adjudicating a node that is not the
 * branch's last one cannot append anything without rewriting what is already there, so it starts a
 * new branch instead, which is what makes going back and trying something else safe.
 *
 * Three tables hold it, and they are the only record that matters:
 *
 *  - wD_LabScenarios: the scenario, and which branch the board is showing.
 *  - wD_LabBranches:  a branch, the sandbox game it plays on, its last node, and the node it is
 *                     currently showing.
 *  - wD_LabNodes:     a position, its parent, and the orders that were played from it.
 *
 * The sandbox games are working space, not history: a branch's game holds whichever node the user
 * is looking at, and is rebuilt from the node whenever that changes. Nodes are never rewritten
 * except to record the orders played from the head of a branch, so an existing branch keeps its
 * positions whatever is done elsewhere in the tree.
 *
 * @package Base
 * @subpackage Lab
 */
class libLabTree
{
	/** @var bool whether the tree's tables have been created, cached for the request */
	private static $tablesReady = false;

	/**
	 * Create the tree's tables if they are not there yet.
	 *
	 * Creating a table implicitly commits in MySQL, so this checks first and only creates when
	 * something is actually missing.
	 */
	public static function ensureTables()
	{
		global $DB;

		if( self::$tablesReady ) return;

		$tabl = $DB->sql_tabl("SHOW TABLES LIKE 'wD_LabNodes'");
		if( $DB->tabl_row($tabl) )
		{
			self::$tablesReady = true;
			return;
		}

		$DB->sql_put("CREATE TABLE IF NOT EXISTS `wD_LabScenarios` (
			`id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
			`userID` mediumint(8) unsigned NOT NULL,
			`name` varchar(120) NOT NULL DEFAULT '',
			`variantID` tinyint(3) unsigned NOT NULL DEFAULT 1,
			`currentBranchID` mediumint(8) unsigned DEFAULT NULL,
			`timeCreated` int(10) unsigned NOT NULL DEFAULT 0,
			`timeLastUsed` int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (`id`),
			KEY `userID` (`userID`,`timeLastUsed`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8");

		$DB->sql_put("CREATE TABLE IF NOT EXISTS `wD_LabBranches` (
			`id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
			`scenarioID` mediumint(8) unsigned NOT NULL,
			`gameID` mediumint(8) unsigned NOT NULL,
			`name` varchar(60) NOT NULL DEFAULT '',
			`parentNodeID` mediumint(8) unsigned DEFAULT NULL,
			`headNodeID` mediumint(8) unsigned DEFAULT NULL,
			`currentNodeID` mediumint(8) unsigned DEFAULT NULL,
			`timeCreated` int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (`id`),
			UNIQUE KEY `gameID` (`gameID`),
			KEY `scenarioID` (`scenarioID`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8");

		$DB->sql_put("CREATE TABLE IF NOT EXISTS `wD_LabNodes` (
			`id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
			`scenarioID` mediumint(8) unsigned NOT NULL,
			`branchID` mediumint(8) unsigned NOT NULL,
			`parentNodeID` mediumint(8) unsigned DEFAULT NULL,
			`turn` smallint(5) NOT NULL DEFAULT 0,
			`phase` varchar(20) NOT NULL DEFAULT 'Diplomacy',
			`positionJSON` mediumtext NOT NULL,
			`timeCreated` int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY (`id`),
			KEY `branchID` (`branchID`,`id`),
			KEY `scenarioID` (`scenarioID`),
			KEY `parentNodeID` (`parentNodeID`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8");

		$DB->sql_put("COMMIT");

		self::$tablesReady = true;
	}

	// --- Reading ----------------------------------------------------------------------------------

	/**
	 * The scenario, branch and node a board belongs to.
	 *
	 * @param int $gameID
	 * @return array|false
	 */
	public static function contextOf($gameID)
	{
		global $DB, $User;

		self::ensureTables();

		$tabl = $DB->sql_tabl("SELECT
				s.id scenarioID, s.name scenarioName, s.variantID, s.userID, s.currentBranchID,
				b.id branchID, b.name branchName, b.gameID, b.headNodeID, b.currentNodeID, b.parentNodeID
			FROM wD_LabBranches b
			INNER JOIN wD_LabScenarios s ON ( s.id = b.scenarioID )
			WHERE b.gameID = ".(int)$gameID);

		$row = $DB->tabl_hash($tabl);

		if( !$row ) return false;

		if( (int)$row['userID'] !== (int)$User->id && !$User->type['Moderator'] )
			throw new Exception(l_t("You can only work on scenarios you created."));

		return $row;
	}

	/**
	 * A branch row, checking that the current user owns its scenario.
	 *
	 * @param int $branchID
	 * @return array
	 * @throws Exception
	 */
	public static function branch($branchID)
	{
		global $DB, $User;

		self::ensureTables();

		$tabl = $DB->sql_tabl("SELECT b.*, s.userID, s.variantID, s.name scenarioName
			FROM wD_LabBranches b
			INNER JOIN wD_LabScenarios s ON ( s.id = b.scenarioID )
			WHERE b.id = ".(int)$branchID);

		$row = $DB->tabl_hash($tabl);

		if( !$row ) throw new Exception(l_t("That branch could not be found."));

		if( (int)$row['userID'] !== (int)$User->id && !$User->type['Moderator'] )
			throw new Exception(l_t("You can only work on scenarios you created."));

		return $row;
	}

	/**
	 * A node row, checking that the current user owns its scenario.
	 *
	 * @param int $nodeID
	 * @return array
	 * @throws Exception
	 */
	public static function node($nodeID)
	{
		global $DB, $User;

		self::ensureTables();

		$tabl = $DB->sql_tabl("SELECT n.*, s.userID
			FROM wD_LabNodes n
			INNER JOIN wD_LabScenarios s ON ( s.id = n.scenarioID )
			WHERE n.id = ".(int)$nodeID);

		$row = $DB->tabl_hash($tabl);

		if( !$row ) throw new Exception(l_t("That position could not be found."));

		if( (int)$row['userID'] !== (int)$User->id && !$User->type['Moderator'] )
			throw new Exception(l_t("You can only work on scenarios you created."));

		return $row;
	}

	/**
	 * The scenario's whole tree, as the board needs it.
	 *
	 * Node positions are left out: the board renders the game, not the snapshot, and the snapshots
	 * are large. What it needs is the shape - which branches exist, which nodes are on each, in
	 * order, and where the board currently is.
	 *
	 * @param int $scenarioID
	 * @return array
	 */
	public static function tree($scenarioID)
	{
		global $DB;

		self::ensureTables();

		$scenarioID = (int)$scenarioID;

		$tabl = $DB->sql_tabl("SELECT id, name, variantID, currentBranchID FROM wD_LabScenarios WHERE id = ".$scenarioID);
		$scenario = $DB->tabl_hash($tabl);

		if( !$scenario ) throw new Exception(l_t("That scenario could not be found."));

		// The database hands everything back as strings; the board compares these to each other and
		// to the ids in its own place, so they are made numbers here rather than in three places
		// on the other side.
		$number = function($value) { return is_null($value) ? null : (int)$value; };

		$branches = array();
		$tabl = $DB->sql_tabl("SELECT id, name, gameID, parentNodeID, headNodeID, currentNodeID
			FROM wD_LabBranches WHERE scenarioID = ".$scenarioID." ORDER BY id");
		while( $row = $DB->tabl_hash($tabl) )
		{
			foreach(array('id','gameID','parentNodeID','headNodeID','currentNodeID') as $field)
				$row[$field] = $number($row[$field]);

			$row['nodes'] = array();
			$branches[$row['id']] = $row;
		}

		$tabl = $DB->sql_tabl("SELECT id, branchID, parentNodeID, turn, phase
			FROM wD_LabNodes WHERE scenarioID = ".$scenarioID." ORDER BY id");
		while( $row = $DB->tabl_hash($tabl) )
		{
			foreach(array('id','branchID','parentNodeID','turn') as $field)
				$row[$field] = $number($row[$field]);

			if( !isset($branches[$row['branchID']]) ) continue;

			$row['year'] = LabPosition::yearFromTurn($row['turn']);
			$row['season'] = LabPosition::seasonFromTurn($row['turn']);
			$row['label'] = self::nodeLabel($row['turn'], $row['phase']);

			$branches[$row['branchID']]['nodes'][] = $row;
		}

		return array(
			'scenarioID' => (int)$scenario['id'],
			'name' => $scenario['name'],
			'variantID' => (int)$scenario['variantID'],
			'currentBranchID' => is_null($scenario['currentBranchID']) ? null : (int)$scenario['currentBranchID'],
			'branches' => array_values($branches)
		);
	}

	/**
	 * How a position is named on the navigation bar, eg "Spring 1901 Movement".
	 *
	 * @param int $turn
	 * @param string $phase
	 * @return string
	 */
	private static function nodeLabel($turn, $phase)
	{
		$phases = array('Diplomacy'=>'Movement', 'Retreats'=>'Retreats', 'Builds'=>'Builds');

		return LabPosition::seasonFromTurn($turn).' '.LabPosition::yearFromTurn($turn).' '.
			(isset($phases[$phase]) ? $phases[$phase] : $phase);
	}

	/**
	 * The current user's scenarios, most recently used first.
	 *
	 * @return array
	 */
	public static function listScenarios()
	{
		global $DB, $User;

		self::ensureTables();

		$scenarios = array();

		$tabl = $DB->sql_tabl("SELECT s.id, s.name, s.variantID, s.timeLastUsed, b.gameID
			FROM wD_LabScenarios s
			LEFT JOIN wD_LabBranches b ON ( b.id = s.currentBranchID )
			WHERE s.userID = ".(int)$User->id."
			ORDER BY s.timeLastUsed DESC, s.id DESC");

		while( $row = $DB->tabl_hash($tabl) ) $scenarios[] = $row;

		return $scenarios;
	}

	// --- Creating ---------------------------------------------------------------------------------

	/**
	 * Start a new scenario on an empty board.
	 *
	 * @param string $name
	 * @param int $variantID
	 * @return array the scenario's context, as contextOf() returns it
	 */
	public static function createScenario($name, $variantID = 1)
	{
		global $DB, $User;

		self::ensureTables();

		$name = libLab::trimName($name);
		if( $name === '' ) $name = 'Scenario '.date('Y-m-d H:i:s');

		$LabGame = processLabGame::createGame($variantID);

		$Position = new LabPosition($LabGame->Variant);
		$LabGame->setPosition($Position);

		libLab::registerGame($LabGame->id, $name);

		$DB->sql_put("INSERT INTO wD_LabScenarios ( userID, name, variantID, timeCreated, timeLastUsed )
			VALUES ( ".(int)$User->id.", '".$DB->escape($name)."', ".(int)$variantID.", ".time().", ".time()." )");
		$scenarioID = (int)$DB->last_inserted();

		$branchID = self::insertBranch($scenarioID, $LabGame->id, 'SC1', null);
		$nodeID = self::insertNode($scenarioID, $branchID, null, $LabGame->getPosition());

		$DB->sql_put("UPDATE wD_LabBranches SET headNodeID = ".$nodeID.", currentNodeID = ".$nodeID."
			WHERE id = ".$branchID);
		$DB->sql_put("UPDATE wD_LabScenarios SET currentBranchID = ".$branchID." WHERE id = ".$scenarioID);
		$DB->sql_put("COMMIT");

		return self::contextOf($LabGame->id);
	}

	/**
	 * @param int $scenarioID
	 * @param int $gameID
	 * @param string $name
	 * @param int|null $parentNodeID
	 * @return int the new branch's ID
	 */
	private static function insertBranch($scenarioID, $gameID, $name, $parentNodeID)
	{
		global $DB;

		$DB->sql_put("INSERT INTO wD_LabBranches ( scenarioID, gameID, name, parentNodeID, timeCreated )
			VALUES (
				".(int)$scenarioID.",
				".(int)$gameID.",
				'".$DB->escape($name)."',
				".( is_null($parentNodeID) ? 'NULL' : (int)$parentNodeID ).",
				".time()."
			)");

		return (int)$DB->last_inserted();
	}

	/**
	 * @param int $scenarioID
	 * @param int $branchID
	 * @param int|null $parentNodeID
	 * @param LabPosition $Position
	 * @return int the new node's ID
	 */
	private static function insertNode($scenarioID, $branchID, $parentNodeID, LabPosition $Position)
	{
		global $DB;

		$DB->sql_put("INSERT INTO wD_LabNodes
			( scenarioID, branchID, parentNodeID, turn, phase, positionJSON, timeCreated )
			VALUES (
				".(int)$scenarioID.",
				".(int)$branchID.",
				".( is_null($parentNodeID) ? 'NULL' : (int)$parentNodeID ).",
				".(int)$Position->turn.",
				'".$DB->escape($Position->phase)."',
				'".$DB->escape($Position->toJSON())."',
				".time()."
			)");

		return (int)$DB->last_inserted();
	}

	/**
	 * The name to give the next branch of a scenario: SC2, SC3, and so on.
	 *
	 * @param int $scenarioID
	 * @return string
	 */
	private static function nextBranchName($scenarioID)
	{
		global $DB;

		$highest = 1;
		$tabl = $DB->sql_tabl("SELECT name FROM wD_LabBranches WHERE scenarioID = ".(int)$scenarioID);
		while( list($name) = $DB->tabl_row($tabl) )
			if( preg_match('/^SC(\d+)$/', $name, $m) && (int)$m[1] > $highest )
				$highest = (int)$m[1];

		list($count) = $DB->sql_row("SELECT COUNT(*) FROM wD_LabBranches WHERE scenarioID = ".(int)$scenarioID);

		return 'SC'.max($highest + 1, (int)$count + 1);
	}

	// --- Navigating -------------------------------------------------------------------------------

	/**
	 * Put a node's position onto its branch's board.
	 *
	 * The node is only read. What changes is the branch's sandbox game, which is working space: it
	 * holds whichever position the user is looking at. Stepping back and forward therefore leaves
	 * the tree exactly as it was.
	 *
	 * @param int $nodeID
	 * @return array the branch's context afterwards
	 */
	public static function selectNode($nodeID)
	{
		global $DB;

		$node = self::node($nodeID);
		$branch = self::branch($node['branchID']);

		if( (int)$branch['currentNodeID'] !== (int)$nodeID )
		{
			$LabGame = processLabGame::loadGame($branch['gameID']);
			$LabGame->setPosition(LabPosition::fromJSON($node['positionJSON']));

			$DB->sql_put("UPDATE wD_LabBranches SET currentNodeID = ".(int)$nodeID." WHERE id = ".(int)$branch['id']);
		}

		$DB->sql_put("UPDATE wD_LabScenarios SET currentBranchID = ".(int)$branch['id'].",
			timeLastUsed = ".time()." WHERE id = ".(int)$branch['scenarioID']);
		$DB->sql_put("COMMIT");

		libLab::touchGame($branch['gameID']);

		return self::contextOf($branch['gameID']);
	}

	/**
	 * Show a branch, at whichever node it was last left on.
	 *
	 * @param int $branchID
	 * @return array the branch's context
	 */
	public static function selectBranch($branchID)
	{
		global $DB;

		$branch = self::branch($branchID);

		$nodeID = is_null($branch['currentNodeID']) ? $branch['headNodeID'] : $branch['currentNodeID'];

		if( is_null($nodeID) ) throw new Exception(l_t("That branch has no positions."));

		return self::selectNode($nodeID);
	}

	/**
	 * The node before or after the one a board is showing, within its own branch.
	 *
	 * @param int $gameID
	 * @param int $step -1 for the previous position, +1 for the next
	 * @return array the branch's context afterwards
	 */
	public static function step($gameID, $step)
	{
		global $DB;

		$context = self::contextOf($gameID);
		if( !$context ) throw new Exception(l_t("That board is not part of a scenario."));

		$currentNodeID = (int)$context['currentNodeID'];

		if( $step < 0 )
			$row = $DB->sql_row("SELECT MAX(id) FROM wD_LabNodes
				WHERE branchID = ".(int)$context['branchID']." AND id < ".$currentNodeID);
		else
			$row = $DB->sql_row("SELECT MIN(id) FROM wD_LabNodes
				WHERE branchID = ".(int)$context['branchID']." AND id > ".$currentNodeID);

		if( $row === false || is_null($row[0]) )
			return $context; // Already at the end of the branch; nothing to do.

		return self::selectNode((int)$row[0]);
	}

	// --- Adjudicating -----------------------------------------------------------------------------

	/**
	 * Adjudicate the orders on a board, and record where that leads.
	 *
	 * At the end of a branch the result is simply the branch's next position. Anywhere else,
	 * appending would mean rewriting what has already been played, so a new branch is started from
	 * this position instead and the adjudication happens there. The branch that was left keeps every
	 * position it had, and is put back to its own last position.
	 *
	 * @param int $gameID
	 * @return array what happened: the context afterwards, and the new branch if one was started
	 */
	public static function ready($gameID)
	{
		global $DB;

		$context = self::contextOf($gameID);
		if( !$context ) throw new Exception(l_t("That board is not part of a scenario."));

		$LabGame = processLabGame::loadGame($gameID);

		// The position as the user has left it, orders and all.
		$played = $LabGame->getPosition();

		$atHead = ( (int)$context['currentNodeID'] === (int)$context['headNodeID'] );

		if( $atHead )
		{
			// Record the orders that were played from here, so that coming back shows them.
			self::updateNodePosition((int)$context['currentNodeID'], $played);

			$LabGame->resolve();

			$nodeID = self::insertNode((int)$context['scenarioID'], (int)$context['branchID'],
				(int)$context['currentNodeID'], $LabGame->getPosition());

			$DB->sql_put("UPDATE wD_LabBranches SET headNodeID = ".$nodeID.", currentNodeID = ".$nodeID."
				WHERE id = ".(int)$context['branchID']);
			$DB->sql_put("UPDATE wD_LabScenarios SET timeLastUsed = ".time()." WHERE id = ".(int)$context['scenarioID']);
			$DB->sql_put("COMMIT");

			libLab::touchGame($gameID);

			return array('context' => self::contextOf($gameID), 'branchCreated' => null);
		}

		// Somewhere in the middle of a branch: start a new one rather than overwrite this one.
		$branchName = self::nextBranchName((int)$context['scenarioID']);

		$NewGame = processLabGame::createGame((int)$context['variantID']);
		$NewGame->setPosition($played);

		libLab::registerGame($NewGame->id, $context['scenarioName'].' - '.$branchName);

		$newBranchID = self::insertBranch((int)$context['scenarioID'], $NewGame->id, $branchName,
			(int)$context['currentNodeID']);

		// The branch starts with this position, carrying the orders that were entered here, so that
		// it can be stepped back to and re-tried on its own.
		$rootNodeID = self::insertNode((int)$context['scenarioID'], $newBranchID,
			(int)$context['currentNodeID'], $played);

		$NewGame->resolve();

		$nodeID = self::insertNode((int)$context['scenarioID'], $newBranchID, $rootNodeID,
			$NewGame->getPosition());

		$DB->sql_put("UPDATE wD_LabBranches SET headNodeID = ".$nodeID.", currentNodeID = ".$nodeID."
			WHERE id = ".$newBranchID);
		$DB->sql_put("UPDATE wD_LabScenarios SET currentBranchID = ".$newBranchID.",
			timeLastUsed = ".time()." WHERE id = ".(int)$context['scenarioID']);
		$DB->sql_put("COMMIT");

		// Put the branch that was left back on its own last position, so that returning to it shows
		// what it always showed rather than the position that was forked from.
		if( !is_null($context['headNodeID']) )
			self::selectNode((int)$context['headNodeID']);

		$DB->sql_put("UPDATE wD_LabScenarios SET currentBranchID = ".$newBranchID."
			WHERE id = ".(int)$context['scenarioID']);
		$DB->sql_put("COMMIT");

		libLab::touchGame($NewGame->id);

		return array('context' => self::contextOf($NewGame->id), 'branchCreated' => $branchName);
	}

	/**
	 * Replace a node's stored position, keeping its identity and its place in the tree.
	 *
	 * Only ever used on the last position of a branch, and only to record the orders that were just
	 * played from it. A position that has already been played on is never rewritten.
	 *
	 * @param int $nodeID
	 * @param LabPosition $Position
	 */
	private static function updateNodePosition($nodeID, LabPosition $Position)
	{
		global $DB;

		$DB->sql_put("UPDATE wD_LabNodes SET
				turn = ".(int)$Position->turn.",
				phase = '".$DB->escape($Position->phase)."',
				positionJSON = '".$DB->escape($Position->toJSON())."'
			WHERE id = ".(int)$nodeID);
		$DB->sql_put("COMMIT");
	}

	/**
	 * Record that the board's position has been edited, so that the tree agrees with the board.
	 *
	 * Editing is only allowed on a Movement phase and only at the end of a branch, so this never
	 * has to rewrite a position that something else was built on.
	 *
	 * @param int $gameID
	 * @return array the context afterwards
	 */
	public static function positionEdited($gameID)
	{
		$context = self::contextOf($gameID);
		if( !$context ) return false;

		$LabGame = processLabGame::loadGame($gameID);

		self::updateNodePosition((int)$context['currentNodeID'], $LabGame->getPosition());

		return self::contextOf($gameID);
	}

	/**
	 * Whether the board's position may be edited.
	 *
	 * A position that something has already been played from is history: editing it would change
	 * what the positions after it grew out of. Retreats and Adjustments are consequences of the
	 * phase before them and are not editable at all.
	 *
	 * @param array $context
	 * @param string $phase
	 * @return string|true true, or why not
	 */
	public static function editRefusal($context, $phase)
	{
		if( $phase !== 'Diplomacy' )
			return l_t("A %s phase follows from the moves before it, so its position cannot be edited. Adjudicate it, or step back.", strtolower($phase));

		if( $context && (int)$context['currentNodeID'] !== (int)$context['headNodeID'] )
			return l_t("This position has already been played from. Adjudicate it to start a new branch, or step forward to the end of this one.");

		return true;
	}

	// --- Branch housekeeping ----------------------------------------------------------------------

	/**
	 * Rename a branch.
	 *
	 * @param int $branchID
	 * @param string $name
	 */
	public static function renameBranch($branchID, $name)
	{
		global $DB;

		$branch = self::branch($branchID);

		$name = trim(strip_tags((string)$name));
		$name = preg_replace('/\s+/', ' ', $name);
		$name = mb_substr($name, 0, 60);

		if( $name === '' ) throw new Exception(l_t("A branch needs a name."));

		$DB->sql_put("UPDATE wD_LabBranches SET name = '".$DB->escape($name)."' WHERE id = ".(int)$branchID);
		$DB->sql_put("COMMIT");

		libLab::renameGame($branch['gameID'], $branch['scenarioName'].' - '.$name);
	}

	/**
	 * Delete a branch, if nothing depends on it.
	 *
	 * A branch that another branch was forked from cannot go: the positions it holds are where that
	 * other branch begins. Neither can the only branch of a scenario, nor the one being shown.
	 *
	 * @param int $branchID
	 */
	public static function deleteBranch($branchID)
	{
		global $DB;

		$branch = self::branch($branchID);
		$scenarioID = (int)$branch['scenarioID'];

		list($branchCount) = $DB->sql_row("SELECT COUNT(*) FROM wD_LabBranches WHERE scenarioID = ".$scenarioID);
		if( $branchCount < 2 )
			throw new Exception(l_t("A scenario needs at least one branch, so this one cannot be deleted."));

		list($currentBranchID) = $DB->sql_row("SELECT currentBranchID FROM wD_LabScenarios WHERE id = ".$scenarioID);
		if( (int)$currentBranchID === (int)$branchID )
			throw new Exception(l_t("This is the branch being shown. Switch to another one first."));

		list($dependents) = $DB->sql_row("SELECT COUNT(*) FROM wD_LabBranches b
			INNER JOIN wD_LabNodes n ON ( n.id = b.parentNodeID )
			WHERE b.scenarioID = ".$scenarioID." AND n.branchID = ".(int)$branchID);
		if( $dependents )
			throw new Exception(l_t("Another branch starts from a position on this one, so deleting it would take that branch's history with it."));

		$DB->sql_put("DELETE FROM wD_LabNodes WHERE branchID = ".(int)$branchID);
		$DB->sql_put("DELETE FROM wD_LabBranches WHERE id = ".(int)$branchID);
		$DB->sql_put("COMMIT");

		libLab::forgetGame($branch['gameID']);
		processGame::eraseGame($branch['gameID'], false);
	}

	/**
	 * Delete a whole scenario and every board behind it.
	 *
	 * @param int $scenarioID
	 */
	public static function deleteScenario($scenarioID)
	{
		global $DB, $User;

		self::ensureTables();

		$scenarioID = (int)$scenarioID;

		$row = $DB->sql_row("SELECT userID FROM wD_LabScenarios WHERE id = ".$scenarioID);
		if( $row === false ) throw new Exception(l_t("That scenario could not be found."));
		if( (int)$row[0] !== (int)$User->id && !$User->type['Moderator'] )
			throw new Exception(l_t("You can only work on scenarios you created."));

		$gameIDs = array();
		$tabl = $DB->sql_tabl("SELECT gameID FROM wD_LabBranches WHERE scenarioID = ".$scenarioID);
		while( list($gameID) = $DB->tabl_row($tabl) ) $gameIDs[] = (int)$gameID;

		$DB->sql_put("DELETE FROM wD_LabNodes WHERE scenarioID = ".$scenarioID);
		$DB->sql_put("DELETE FROM wD_LabBranches WHERE scenarioID = ".$scenarioID);
		$DB->sql_put("DELETE FROM wD_LabScenarios WHERE id = ".$scenarioID);
		$DB->sql_put("COMMIT");

		foreach($gameIDs as $gameID)
		{
			libLab::forgetGame($gameID);
			processGame::eraseGame($gameID, false);
		}
	}

	/**
	 * The board the owner should land on: the scenario they were last working on, or a new one.
	 *
	 * @return int the game ID of that board
	 */
	public static function currentOrNewGameID()
	{
		$scenarios = self::listScenarios();

		foreach($scenarios as $scenario)
			if( !is_null($scenario['gameID']) ) return (int)$scenario['gameID'];

		$context = self::createScenario('');

		return (int)$context['gameID'];
	}
}
