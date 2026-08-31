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
 * Check that a Diplomacy Lab position survives being snapshotted and restored.
 *
 * The invariant this proves is the one the analysis tree rests on:
 *
 *     taking a snapshot, destroying the board, restoring the snapshot and carrying on
 *     is indistinguishable from never having left.
 *
 * It is checked for all three phases, and for Retreats and Builds the phase is *reached by
 * resolving*, never set up by hand: a dislodgement is a consequence of a Movement phase and an
 * adjustment is a consequence of a Fall turn, so those are the only honest ways to produce one.
 *
 * Legality is never recomputed here. Legal retreat destinations come from the same SQL
 * board/orders/retreats.php uses to accept a retreat, and legal build destinations from the same
 * SQL gamemaster/orders/builds.php uses to decide how many builds a power gets, so what is
 * compared is what the engine itself would allow.
 *
 * Run it against a database that can be written to - a development or throwaway one, not a
 * database holding positions you care about, since it creates Lab boards as it goes. Run it as
 * the same user the web server runs as, or the cache and order-log directories it creates along
 * the way will be owned by someone the web server cannot write as:
 *
 *     sudo -u www-data php install/DiplomacyLab/checkSnapshots.php
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

	if( !$ok )
	{
		print "        got      ".trim(print_r($actual, true))."\n";
		print "        expected ".trim(print_r($expected, true))."\n";
	}
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

function terrName($id)
{
	global $DB;

	if( is_null($id) ) return '-';

	list($name) = $DB->sql_row("SELECT name FROM wD_Territories WHERE mapID = 1 AND id = ".(int)$id);

	return $name ? $name : ('#'.(int)$id);
}

function countryID($name)
{
	global $Variant;

	return $Variant->countryID($name);
}

/**
 * Everything about a game's board state, as comparable text.
 *
 * Units are listed in ID order, which is the order a restored position writes them in, and
 * everything that points at a unit points at its position in that list. Nothing here is an ID
 * that changes between games, so two games in the same state produce the same fingerprint.
 */
function fingerprint($gameID)
{
	global $DB;

	$gameID = (int)$gameID;
	$out = array();

	list($turn, $phase) = $DB->sql_row("SELECT turn, phase FROM wD_Games WHERE id = ".$gameID);
	$out[] = 'turn '.$turn.' '.$phase;

	$unitIndex = array();
	$tabl = $DB->sql_tabl("SELECT id, countryID, type, terrID FROM wD_Units WHERE gameID = ".$gameID." ORDER BY id");
	while( list($id, $countryID, $type, $tID) = $DB->tabl_row($tabl) )
	{
		$index = count($unitIndex);
		$unitIndex[(int)$id] = $index;
		$out[] = 'unit '.$index.': country '.$countryID.' '.$type.' in '.terrName($tID);
	}

	$unit = function($unitID) use ($unitIndex) {
		return is_null($unitID) ? '-' : ( isset($unitIndex[(int)$unitID]) ? 'unit '.$unitIndex[(int)$unitID] : '?' );
	};

	$rows = array();
	$tabl = $DB->sql_tabl("SELECT terrID, countryID, standoff, occupiedFromTerrID, occupyingUnitID, retreatingUnitID
		FROM wD_TerrStatus WHERE gameID = ".$gameID);
	while( $row = $DB->tabl_hash($tabl) )
		$rows[] = 'territory '.terrName($row['terrID'])
			.': owner '.(int)$row['countryID']
			.', standoff '.$row['standoff']
			.', occupied from '.terrName($row['occupiedFromTerrID'])
			.', occupied by '.$unit($row['occupyingUnitID'])
			.', retreating '.$unit($row['retreatingUnitID']);
	sort($rows);
	$out = array_merge($out, $rows);

	$rows = array();
	$tabl = $DB->sql_tabl("SELECT countryID, type, unitID, toTerrID, fromTerrID, viaConvoy
		FROM wD_Orders WHERE gameID = ".$gameID);
	while( $row = $DB->tabl_hash($tabl) )
		$rows[] = 'order country '.(int)$row['countryID'].': '.$row['type']
			.' by '.$unit($row['unitID'])
			.' to '.terrName($row['toTerrID'])
			.' from '.terrName($row['fromTerrID'])
			.' via '.(is_null($row['viaConvoy']) ? '-' : $row['viaConvoy']);
	sort($rows);
	$out = array_merge($out, $rows);

	$tabl = $DB->sql_tabl("SELECT countryID, status, orderStatus, supplyCenterNo, unitNo
		FROM wD_Members WHERE gameID = ".$gameID." ORDER BY countryID");
	while( $row = $DB->tabl_hash($tabl) )
		$out[] = 'country '.(int)$row['countryID'].': '.$row['status']
			.', orders "'.$row['orderStatus'].'"'
			.', '.(int)$row['supplyCenterNo'].' centers, '.(int)$row['unitNo'].' units';

	return implode("\n", $out);
}

/**
 * Where each dislodged unit may legally retreat to, decided by the same SQL the engine uses to
 * accept a retreat order (board/orders/retreats.php), with the destination left unbound so that
 * it lists them rather than checking one.
 */
function legalRetreatDests($gameID)
{
	global $DB, $Variant;

	$gameID = (int)$gameID;
	$dests = array();

	$tabl = $DB->sql_tabl("SELECT retreatingUnit.id, retreatingUnit.type, retreatingUnit.terrID, retreatingUnit.countryID
		FROM wD_Units retreatingUnit
		INNER JOIN wD_TerrStatus retreatingFrom ON ( retreatingFrom.retreatingUnitID = retreatingUnit.id )
		WHERE retreatingFrom.gameID = ".$gameID);

	$units = array();
	while( $row = $DB->tabl_hash($tabl) ) $units[] = $row;

	foreach($units as $unitRow)
	{
		$found = array();

		$tabl = $DB->sql_tabl("SELECT linkBorder.toTerrID
			FROM wD_Units retreatingUnit
			INNER JOIN wD_TerrStatus retreatingFrom
				ON ( retreatingFrom.retreatingUnitID = retreatingUnit.id )
			INNER JOIN wD_CoastalBorders linkBorder
				ON (
					linkBorder.mapID = ".$Variant->mapID." AND
					linkBorder.fromTerrID = retreatingUnit.terrID
					AND (
						retreatingFrom.occupiedFromTerrID IS NULL
						OR NOT ".$Variant->deCoastCompare('retreatingFrom.occupiedFromTerrID','linkBorder.toTerrID')."
					)
				)
			LEFT JOIN wD_TerrStatus retreatingTo
				ON (
					".$Variant->deCoastCompare('retreatingTo.terrID','linkBorder.toTerrID')."
					AND retreatingFrom.gameID = retreatingTo.gameID
				)
			WHERE retreatingUnit.id = ".(int)$unitRow['id']."
				AND linkBorder.".strtolower($unitRow['type'])."sPass = 'Yes'
				AND retreatingTo.occupyingUnitID IS NULL
				AND ( retreatingTo.standoff IS NULL OR retreatingTo.standoff = 'No' )");

		while( list($toTerrID) = $DB->tabl_row($tabl) ) $found[] = terrName($toTerrID);

		sort($found);

		$dests[] = 'country '.(int)$unitRow['countryID'].' '.$unitRow['type'].' in '
			.terrName($unitRow['terrID']).' may retreat to: '.implode(', ', $found);
	}

	sort($dests);

	return implode("\n", $dests);
}

/**
 * Where each power may legally build, decided by the same criterion gamemaster/orders/builds.php
 * counts its builds with: a home supply center it owns, with nothing standing on it.
 */
function legalBuildDests($gameID)
{
	global $DB, $Variant;

	$gameID = (int)$gameID;
	$out = array();

	$tabl = $DB->sql_tabl("SELECT countryID, supplyCenterNo, unitNo FROM wD_Members WHERE gameID = ".$gameID." ORDER BY countryID");
	$members = array();
	while( $row = $DB->tabl_hash($tabl) ) $members[] = $row;

	foreach($members as $member)
	{
		$countryID = (int)$member['countryID'];
		$difference = (int)$member['supplyCenterNo'] - (int)$member['unitNo'];

		$dests = array();
		$tabl = $DB->sql_tabl("SELECT ts.terrID
			FROM wD_TerrStatus ts
			INNER JOIN wD_Territories t ON ( t.id = ts.terrID )
			WHERE ts.gameID = ".$gameID."
				AND ts.countryID = ".$countryID."
				AND t.countryID = ".$countryID."
				AND ts.occupyingUnitID IS NULL
				AND t.supply = 'Yes'
				AND t.mapID = ".$Variant->mapID);
		while( list($tID) = $DB->tabl_row($tabl) ) $dests[] = terrName($tID);
		sort($dests);

		$out[] = 'country '.$countryID.': '.($difference > 0 ? '+'.$difference : $difference)
			.', may build in: '.implode(', ', $dests);
	}

	return implode("\n", $out);
}

/** Destroy a game's board state, as though something had gone very wrong. */
function wipe($gameID)
{
	global $DB;

	$gameID = (int)$gameID;

	$DB->sql_put("DELETE FROM wD_Orders WHERE gameID = ".$gameID);
	$DB->sql_put("DELETE FROM wD_TerrStatus WHERE gameID = ".$gameID);
	$DB->sql_put("DELETE FROM wD_Units WHERE gameID = ".$gameID);
	$DB->sql_put("UPDATE wD_Games SET turn = 0, phase = 'Diplomacy' WHERE id = ".$gameID);
	$DB->sql_put("UPDATE wD_Members SET supplyCenterNo = 0, unitNo = 0, orderStatus = 'None' WHERE gameID = ".$gameID);
	$DB->sql_put("COMMIT");
}

/** A Lab board holding the given position. */
function board($name, $units, $centers = array(), $turn = 0, $phase = 'Diplomacy')
{
	global $Variant;

	$Lab = quiet(function() { return processLabGame::createGame(1); });
	libLab::registerGame($Lab->id, $name);

	$Position = new LabPosition($Variant);
	$Position->turn = $turn;
	$Position->phase = $phase;

	foreach($units as $unit)
		$Position->units[] = array('countryID'=>countryID($unit[0]), 'type'=>$unit[1], 'terrID'=>terrID($unit[2]));
	foreach($centers as $center)
		$Position->centers[] = array('countryID'=>countryID($center[0]), 'terrID'=>terrID($center[1]));

	quiet(function() use ($Lab, $Position) { $Lab->setPosition($Position); });

	return $Lab;
}

/** Fill in an order for the unit standing in a territory. */
function order($gameID, $unitTerritory, $type, $to = null, $from = null, $via = 'No')
{
	global $DB;

	$gameID = (int)$gameID;

	list($unitID) = $DB->sql_row("SELECT id FROM wD_Units WHERE gameID = ".$gameID." AND terrID = ".terrID($unitTerritory));
	if( !$unitID ) throw new Exception("There is no unit in ".$unitTerritory.".");

	$DB->sql_put("UPDATE wD_Orders SET
			type = '".$DB->escape($type)."',
			toTerrID = ".( is_null($to) ? 'NULL' : terrID($to) ).",
			fromTerrID = ".( is_null($from) ? 'NULL' : terrID($from) ).",
			viaConvoy = '".$via."'
		WHERE gameID = ".$gameID." AND unitID = ".(int)$unitID);
	$DB->sql_put("COMMIT");
}

/** Fill in one build order for a country. */
function buildOrder($gameID, $country, $type, $territory)
{
	global $DB;

	$gameID = (int)$gameID;
	$cID = countryID($country);

	list($orderID) = $DB->sql_row("SELECT id FROM wD_Orders
		WHERE gameID = ".$gameID." AND countryID = ".$cID." AND type LIKE 'Build%' ORDER BY id LIMIT 1");
	if( !$orderID ) throw new Exception("There is no build order waiting for ".$country.".");

	$DB->sql_put("UPDATE wD_Orders SET type = '".$DB->escape($type)."', toTerrID = ".terrID($territory)."
		WHERE id = ".(int)$orderID);
	$DB->sql_put("COMMIT");
}

print "=== Diplomacy Lab: snapshot and restore, against the real engine ===\n\n";

// -------------------------------------------------------------------------------------------
print "1. A Movement position built by hand\n";

$movement = board('Snapshot check: movement',
	array(
		array('France', 'Army', 'Picardy'),
		array('France', 'Army', 'Burgundy'),
		array('France', 'Fleet', 'English Channel'),
		array('Germany', 'Army', 'Ruhr'),
		array('Germany', 'Army', 'Munich'),
	),
	array(
		array('France', 'Paris'), array('France', 'Brest'), array('France', 'Marseilles'),
		array('Germany', 'Berlin'),
	),
	LabPosition::turnFromYearSeason(1903, 'Fall'));

$before = fingerprint($movement->id);
$snapshot = $movement->getPosition();

check('the position is an arbitrary one: more centers than units for France, none for Italy',
	substr_count($before, 'country '.countryID('Italy').': Playing, orders "None", 0 centers, 0 units'), 1);

// A position someone is building has nothing but units, centers and a date; the engine state only
// exists once a game is holding the position, and reading it back picks it up.
$inTheEditor = new LabPosition($Variant);
$inTheEditor->units[] = array('countryID'=>countryID('France'), 'type'=>'Army', 'terrID'=>terrID('Paris'));
check('a position being built by hand carries no engine state', $inTheEditor->hasEngineState(), false);
check('reading one back out of a game does', $snapshot->hasEngineState(), true);
check('and editing it drops that state again',
	(function() use ($snapshot) {
		$edited = LabPosition::fromJSON($snapshot->toJSON());
		$edited->clearEngineState();
		return $edited->hasEngineState();
	})(), false);

wipe($movement->id);
check('the board really was destroyed', fingerprint($movement->id) === $before, false);

$movement = quiet(function() use ($movement) { return processLabGame::loadGame($movement->id); });
quiet(function() use ($movement, $snapshot) { $movement->setPosition($snapshot); });

check('restoring puts the Movement position back exactly', fingerprint($movement->id), $before);

// -------------------------------------------------------------------------------------------
print "\n2. A Retreats phase, reached by resolving a real dislodgement\n";

$retreats = board('Snapshot check: retreats',
	array(
		array('France', 'Army', 'Burgundy'),
		array('France', 'Army', 'Paris'),
		array('Germany', 'Army', 'Munich'),
		array('Germany', 'Army', 'Ruhr'),
	),
	array(
		array('France', 'Paris'), array('France', 'Marseilles'),
		array('Germany', 'Munich'), array('Germany', 'Berlin'),
	),
	LabPosition::turnFromYearSeason(1903, 'Spring'));

order($retreats->id, 'Burgundy', 'Hold');
order($retreats->id, 'Paris', 'Hold');
order($retreats->id, 'Munich', 'Move', 'Burgundy');
order($retreats->id, 'Ruhr', 'Support move', 'Burgundy', 'Munich');

quiet(function() use ($retreats) { $retreats->resolve(); });
$retreats = quiet(function() use ($retreats) { return processLabGame::loadGame($retreats->id); });

check('resolving a supported attack produced a Retreats phase', $retreats->phase, 'Retreats');

list($sharing) = $DB->sql_row("SELECT COUNT(*) FROM wD_Units WHERE gameID = ".$retreats->id."
	AND terrID = ".terrID('Burgundy'));
check('two units legitimately share Burgundy while the retreat is pending', (int)$sharing, 2);

$beforeRetreat = fingerprint($retreats->id);
$destsBefore = legalRetreatDests($retreats->id);

$retreatSnapshot = $retreats->getPosition();

check('the snapshot carries engine state', $retreatSnapshot->hasEngineState(), true);

$retreatingUnits = 0;
foreach($retreatSnapshot->territoryStatus as $status)
	if( !is_null($status['retreatingUnit']) ) $retreatingUnits++;
check('the snapshot records exactly one dislodged unit', $retreatingUnits, 1);

check('the dislodged unit has somewhere to go',
	strpos($destsBefore, 'may retreat to: ') !== false && !preg_match('/may retreat to: $/', $destsBefore), true);

wipe($retreats->id);
$retreats = quiet(function() use ($retreats) { return processLabGame::loadGame($retreats->id); });
quiet(function() use ($retreats, $retreatSnapshot) { $retreats->setPosition($retreatSnapshot); });

check('restoring puts the Retreats phase back exactly', fingerprint($retreats->id), $beforeRetreat);
check('the legal retreat destinations are unchanged', legalRetreatDests($retreats->id), $destsBefore);

// Carrying on from the restored phase must give the same result as carrying on from the original.
$control = quiet(function() { return processLabGame::createGame(1); });
libLab::registerGame($control->id, 'Snapshot check: retreats control');
quiet(function() use ($control, $retreatSnapshot) { $control->setPosition($retreatSnapshot); });

foreach(array($retreats, $control) as $game)
{
	$game = quiet(function() use ($game) { return processLabGame::loadGame($game->id); });
	order($game->id, 'Burgundy', 'Retreat', 'Gascony');
	quiet(function() use ($game) { $game->resolve(); });
}

check('resolving the same retreat gives the same position on both boards',
	fingerprint($retreats->id), fingerprint($control->id));

$retreats = quiet(function() use ($retreats) { return processLabGame::loadGame($retreats->id); });
check('the retreating army actually went to Gascony', substr_count(fingerprint($retreats->id), 'in Gascony'), 1);

// -------------------------------------------------------------------------------------------
print "\n3. An Adjustments phase, reached by normal progression\n";

$builds = board('Snapshot check: builds',
	array(
		array('France', 'Army', 'Paris'),
		array('France', 'Army', 'Marseilles'),
		array('Germany', 'Army', 'Munich'),
	),
	array(
		array('France', 'Paris'), array('France', 'Marseilles'), array('France', 'Brest'),
		array('Germany', 'Munich'),
	),
	LabPosition::turnFromYearSeason(1903, 'Fall'));

order($builds->id, 'Paris', 'Hold');
order($builds->id, 'Marseilles', 'Hold');
order($builds->id, 'Munich', 'Hold');

quiet(function() use ($builds) { $builds->resolve(); });
$builds = quiet(function() use ($builds) { return processLabGame::loadGame($builds->id); });

check('a Fall turn with more centers than units produced a Builds phase', $builds->phase, 'Builds');

$beforeBuild = fingerprint($builds->id);
$buildsBefore = legalBuildDests($builds->id);
$buildSnapshot = $builds->getPosition();

check('the Builds snapshot carries engine state', $buildSnapshot->hasEngineState(), true);
check('France is owed exactly one build',
	substr_count($buildsBefore, 'country '.countryID('France').': +1'), 1);

wipe($builds->id);
$builds = quiet(function() use ($builds) { return processLabGame::loadGame($builds->id); });
quiet(function() use ($builds, $buildSnapshot) { $builds->setPosition($buildSnapshot); });

check('restoring puts the Builds phase back exactly', fingerprint($builds->id), $beforeBuild);
check('the legal builds and disbands are unchanged', legalBuildDests($builds->id), $buildsBefore);

$buildControl = quiet(function() { return processLabGame::createGame(1); });
libLab::registerGame($buildControl->id, 'Snapshot check: builds control');
quiet(function() use ($buildControl, $buildSnapshot) { $buildControl->setPosition($buildSnapshot); });

foreach(array($builds, $buildControl) as $game)
{
	$game = quiet(function() use ($game) { return processLabGame::loadGame($game->id); });
	buildOrder($game->id, 'France', 'Build Army', 'Brest');
	quiet(function() use ($game) { $game->resolve(); });
}

check('building from the restored phase gives the same next Spring on both boards',
	fingerprint($builds->id), fingerprint($buildControl->id));

$builds = quiet(function() use ($builds) { return processLabGame::loadGame($builds->id); });
check('the game moved on to the next Movement phase', $builds->phase, 'Diplomacy');
check('the new army was built in Brest', substr_count(fingerprint($builds->id), 'Army in Brest'), 1);

// -------------------------------------------------------------------------------------------
print "\n4. A board that starts in a year of its own can still read its history back\n";

// The board renders a Movement phase using the ownership archived for the turn *before* it
// (api/responses/game_state.php looks up $inGameCenters[$turn - 1] and throws if it is missing).
// A position starting in 1903 has no 1902 to look up, so applying a position seeds one.
foreach(array('movement' => $movement, 'retreats' => $retreats, 'builds' => $builds) as $label => $game)
{
	$game = quiet(function() use ($game) { return processLabGame::loadGame($game->id); });

	list($archived) = $DB->sql_row("SELECT COUNT(*) FROM wD_TerrStatusArchive
		WHERE gameID = ".$game->id." AND turn = ".((int)$game->turn - 1));

	check('the '.$label.' board has the earlier ownership its history is read against',
		(int)$archived > 0, true);
}

// -------------------------------------------------------------------------------------------
print "\n5. Older files still load\n";

$v1 = json_encode(array(
	'format' => 'diplomacy-lab-position',
	'formatVersion' => 1,
	'variant' => 'Classic',
	'year' => 1905, 'season' => 'Spring', 'phase' => 'Diplomacy',
	'units' => array(
		array('country'=>'France', 'type'=>'Army', 'territory'=>'Picardy'),
		array('country'=>'Russia', 'type'=>'Fleet', 'territory'=>'Spain (North Coast)')
	),
	'centers' => array( array('country'=>'France', 'territory'=>'Paris') )
));

$loaded = LabPosition::fromJSON($v1);
check('a version 1 file still loads', count($loaded->units), 2);
check('it has no engine state, so it restores the way it always did', $loaded->hasEngineState(), false);
check('its calendar survives', array($loaded->year(), $loaded->season()), array(1905, 'Spring'));

$roundTrip = LabPosition::fromJSON($retreatSnapshot->toJSON());
check('a version 2 snapshot survives being written to JSON and read back',
	$roundTrip->toJSON(), $retreatSnapshot->toJSON());

// -------------------------------------------------------------------------------------------
print "\n6. Tidying up\n";

foreach(array($movement, $retreats, $control, $builds, $buildControl) as $game)
{
	quiet(function() use ($game) {
		$Lab = processLabGame::loadGame($game->id);
		$Lab->deleteGame();
		libLab::forgetGame($game->id);
	});
}
print "  removed the boards this check created\n";

print "\n".($checks - $failures)."/".$checks." checks passed.\n";

exit($failures ? 1 : 0);
