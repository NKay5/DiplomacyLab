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

/**
 * Check that a Lab position can start in any year and still read its history back after RESOLVE.
 *
 * The board renders each phase using the supply center ownership archived for the turn *before*
 * it. webDiplomacy can rely on that turn having been played; a Lab position cannot, because it may
 * be set up in any year, and then the turn before it never existed. Without something there,
 * api/responses/game_state.php - which is what the board's game/status call runs - throws
 * "no centers found for turn N", and the board cannot draw the position at all.
 *
 * This check builds a board in several years, resolves each one through the real engine, and then
 * loads the game state exactly as the board does. It fails on the unfixed code for every year but
 * 1901, which is why the fault went unnoticed: the first year is the only one that needs nothing
 * before it.
 *
 * Run it against a database that can be written to - a development or throwaway one, not a
 * database holding positions you care about, since it creates Lab boards as it goes. Run it as the
 * user the web server runs as, or the cache directories it creates along the way will be owned by
 * someone the web server cannot write as:
 *
 *     sudo -u www-data php install/DiplomacyLab/checkPositionYears.php
 *
 * @package Base
 * @subpackage Lab
 */

define('IN_CODE', true);

if( php_sapi_name() !== 'cli' ) die("This script is for the command line.\n");

chdir(dirname(__FILE__).'/../..');

ob_start();
require_once('config.php');
require_once('header.php');
require_once(l_r('lib/variant.php'));
require_once(l_r('objects/labPosition.php'));
require_once(l_r('gamemaster/labGame.php'));
require_once(l_r('lib/lab.php'));
require_once(l_r('api/responses/game_state.php'));
ob_end_clean();

global $DB, $User, $Variant;

$ownerName = isset($argv[1]) ? $argv[1] : 'owner';
list($userID) = $DB->sql_row("SELECT id FROM wD_Users WHERE username = '".$DB->escape($ownerName)."'");
if( !$userID ) die("No such user '".$ownerName."'. Pass the Lab owner's username as the first argument.\n");

$User = new User((int)$userID);
$GLOBALS['User'] = $User;

$Variant = libVariant::loadFromVariantName('Classic');
libVariant::setGlobals($Variant);

$checks = 0;
$failures = 0;

function check($label, $actual, $expected = true)
{
	global $checks, $failures;

	$checks++;
	$ok = ($actual === $expected);
	if( !$ok ) $failures++;

	print '  '.($ok ? 'ok  ' : 'FAIL').' '.$label."\n";

	if( !$ok ) print "        got ".trim(print_r($actual, true))."\n";
}

/** Run something that prints, without letting it print. */
function quiet($fn)
{
	ob_start();
	try { $result = $fn(); } finally { ob_end_clean(); }
	return $result;
}

function terrID($name)
{
	global $DB;

	list($id) = $DB->sql_row("SELECT id FROM wD_Territories WHERE mapID = 1 AND name = '".$DB->escape($name)."'");
	if( !$id ) throw new Exception("There is no territory called '".$name."'.");

	return (int)$id;
}

/** Load the game state the way the board's game/status call does, returning any complaint. */
function historyProblem($gameID, $countryID)
{
	try
	{
		quiet(function() use ($gameID, $countryID) {
			$gameState = new \webdiplomacy_api\GameState((int)$gameID, (int)$countryID);
			$gameState->load();
		});
	}
	catch(Exception $e) { return $e->getMessage(); }

	return '';
}

print "=== Diplomacy Lab: a position may start in any year ===\n\n";

$boards = array();

foreach(array(array(1901, 'Spring'), array(1903, 'Spring'), array(1905, 'Fall')) as $when)
{
	list($year, $season) = $when;

	print $year.' '.$season."\n";

	$Lab = quiet(function() { return processLabGame::createGame(1); });
	libLab::registerGame($Lab->id, 'Year check '.$year.' '.$season);
	$boards[] = $Lab;

	$Position = new LabPosition($Variant);
	$Position->turn = LabPosition::turnFromYearSeason($year, $season);

	foreach(array(
		array('France', 'Army', 'Picardy'), array('France', 'Army', 'Burgundy'),
		array('Germany', 'Army', 'Ruhr'), array('Germany', 'Army', 'Munich'),
	) as $unit)
		$Position->units[] = array('countryID'=>$Variant->countryID($unit[0]), 'type'=>$unit[1], 'terrID'=>terrID($unit[2]));

	foreach(array(
		array('France', 'Paris'), array('France', 'Brest'),
		array('Germany', 'Berlin'), array('Germany', 'Munich'),
	) as $center)
		$Position->centers[] = array('countryID'=>$Variant->countryID($center[0]), 'terrID'=>terrID($center[1]));

	quiet(function() use ($Lab, $Position) { $Lab->setPosition($Position); });
	libLab::saveSnapshot($Lab->id, $Position);

	check('the board is set up in '.$year, historyProblem($Lab->id, $Variant->countryID('France')), '');

	// One move and three holds, through webDiplomacy's own order rows.
	foreach(array(
		array('Picardy', 'Move', 'Belgium'), array('Burgundy', 'Hold', null),
		array('Ruhr', 'Hold', null), array('Munich', 'Hold', null),
	) as $order)
	{
		list($unitID) = $DB->sql_row("SELECT id FROM wD_Units WHERE gameID = ".$Lab->id." AND terrID = ".terrID($order[0]));
		$DB->sql_put("UPDATE wD_Orders SET type = '".$order[1]."',
				toTerrID = ".( is_null($order[2]) ? 'NULL' : terrID($order[2]) ).",
				fromTerrID = NULL, viaConvoy = 'No'
			WHERE gameID = ".$Lab->id." AND unitID = ".(int)$unitID);
	}
	$DB->sql_put("COMMIT");

	quiet(function() use ($Lab) { $Lab->resolve(); });
	$Lab = quiet(function() use ($Lab) { return processLabGame::loadGame($Lab->id); });

	check('it still reads its own history after RESOLVE', historyProblem($Lab->id, $Variant->countryID('France')), '');

	list($moved) = $DB->sql_row("SELECT COUNT(*) FROM wD_Units WHERE gameID = ".$Lab->id." AND terrID = ".terrID('Belgium'));
	check('the move was adjudicated by the engine', (int)$moved, 1);

	// RESET restores the position that was snapshotted before adjudicating, and it too has to be
	// readable afterwards.
	$snapshot = libLab::loadSnapshot($Lab->id);
	quiet(function() use ($Lab, $snapshot) { $Lab->setPosition($snapshot); });
	$Lab = quiet(function() use ($Lab) { return processLabGame::loadGame($Lab->id); });

	check('and after RESET', historyProblem($Lab->id, $Variant->countryID('France')), '');
	check('which put the year back', array((int)$Lab->turn, $Lab->phase),
		array(LabPosition::turnFromYearSeason($year, $season), 'Diplomacy'));
}

print "\nTidying up\n";
foreach($boards as $board)
	quiet(function() use ($board) {
		$Lab = processLabGame::loadGame($board->id);
		$Lab->deleteGame();
		libLab::forgetGame($board->id);
	});
print "  removed the boards this check created\n";

print "\n".($checks - $failures)."/".$checks." checks passed.\n";

exit($failures ? 1 : 0);
