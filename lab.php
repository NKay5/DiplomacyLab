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
 * Diplomacy Lab: a free tactical analysis board built on webDiplomacy.
 *
 * The Lab is a thin layer over webDiplomacy's existing sandbox games. It does not reimplement
 * anything: the map, the order interface, the arrows and results, and above all the adjudicator
 * are webDiplomacy's own. What the Lab adds is the ability to put the board in any position at
 * all, resolve it in one click, and step straight back to where you were.
 *
 * Every action here funnels through LabPosition (objects/labPosition.php) and processLabGame
 * (gamemaster/labGame.php), so there is one code path that writes board state.
 *
 * @package Base
 * @subpackage Lab
 */

define('IN_CODE',true);
require_once('config.php');

require_once('header.php');

require_once(l_r('objects/labPosition.php'));
require_once(l_r('gamemaster/labGame.php'));
require_once(l_r('lib/lab.php'));

if( $Misc->Panic )
	libHTML::notice(l_t('Diplomacy Lab disabled'),
		l_t("Diplomacy Lab has been temporarily disabled while an unexpected problem is taken care of. Please try again later."));

if( !$User->type['User'] )
	libHTML::notice(l_t('Not logged on'),
		l_t("Only a logged on user can use Diplomacy Lab. Please <a href='logon.php' class='light'>log on</a> first."));

libLab::ensureTables();

$labGameID = isset($_REQUEST['gameID']) ? (int)$_REQUEST['gameID'] : 0;
$labNotice = '';
$labError = '';

/*
 * Exporting a position is handled before any HTML is produced so the browser gets a plain JSON
 * download. This is the same format that can be imported again, and the format an external
 * program or model would generate to set up a position.
 */
if( $labGameID && isset($_REQUEST['export']) )
{
	try
	{
		$LabGame = processLabGame::loadGame($labGameID);
		$Position = $LabGame->getPosition();

		$labRecord = libLab::getGame($labGameID);
		if( $labRecord ) $Position->name = $labRecord['name'];

		$filename = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $Position->name);
		if( $filename === '' ) $filename = 'position';

		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="'.$filename.'.json"');
		print $Position->toJSON();
		die();
	}
	catch(Exception $e)
	{
		libHTML::error($e->getMessage());
	}
}

/*
 * All Lab actions are ordinary form posts handled here, before any output, so that each one can
 * finish with a redirect. That keeps reloads from repeating an adjudication.
 */
if( isset($_POST['labAction']) )
{
	$redirectTo = 'lab.php'.($labGameID ? '?gameID='.$labGameID : '');

	try
	{
		libAuth::formToken_Valid();

		$action = $_POST['labAction'];

		switch($action)
		{
			case 'new':
			{
				$variantID = isset($_POST['variantID']) ? (int)$_POST['variantID'] : 1;
				if( !isset(Config::$variants[$variantID]) ) $variantID = 1;

				$LabGame = processLabGame::createGame($variantID);

				// A new board is empty: no units anywhere and every supply center neutral.
				$Position = new LabPosition($LabGame->Variant);
				$LabGame->setPosition($Position);

				$name = isset($_POST['name']) ? $_POST['name'] : '';
				if( libLab::trimName($name) === '' ) $name = 'Position '.date('Y-m-d H:i');
				libLab::registerGame($LabGame->id, $name);
				libLab::saveSnapshot($LabGame->id, $Position);

				$redirectTo = 'lab.php?gameID='.$LabGame->id.'&mode=edit&notice=created';
				break;
			}

			case 'applyPosition':
			{
				$LabGame = processLabGame::loadGame($labGameID);

				$Position = labBuildPositionFromForm($LabGame);
				$LabGame->setPosition($Position);

				// The position you just built becomes the point RESET comes back to.
				libLab::saveSnapshot($LabGame->id, $Position);
				libLab::touchGame($LabGame->id);

				$redirectTo = 'lab.php?gameID='.$LabGame->id.'&notice=positionSet';
				break;
			}

			case 'setTurn':
			{
				$LabGame = processLabGame::loadGame($labGameID);

				// Keep the pieces where they are and only move the calendar
				$Position = $LabGame->getPosition();
				$Position->turn = LabPosition::turnFromYearSeason(
					isset($_POST['year']) ? (int)$_POST['year'] : 1901,
					isset($_POST['season']) ? $_POST['season'] : 'Spring'
				);
				$Position->phase = LabPosition::normalizePhase(isset($_POST['phase']) ? $_POST['phase'] : 'Diplomacy');

				$LabGame->setPosition($Position);
				libLab::saveSnapshot($LabGame->id, $Position);
				libLab::touchGame($LabGame->id);

				$redirectTo = 'lab.php?gameID='.$LabGame->id.'&notice=turnSet';
				break;
			}

			case 'resolve':
			{
				$LabGame = processLabGame::loadGame($labGameID);

				// Snapshot before adjudicating; this is what RESET restores.
				libLab::saveSnapshot($LabGame->id, $LabGame->getPosition());

				$LabGame->resolve();
				libLab::touchGame($LabGame->id);

				$redirectTo = 'lab.php?gameID='.$LabGame->id.'&notice=resolved';
				break;
			}

			case 'reset':
			{
				$LabGame = processLabGame::loadGame($labGameID);

				$Position = libLab::loadSnapshot($LabGame->id);
				if( $Position === false )
					throw new Exception(l_t("There is nothing to reset to yet; resolve a position first."));

				$LabGame->setPosition($Position);
				libLab::touchGame($LabGame->id);

				$redirectTo = 'lab.php?gameID='.$LabGame->id.'&notice=reset';
				break;
			}

			case 'duplicate':
			{
				$LabGame = processLabGame::loadGame($labGameID);
				$Copy = $LabGame->duplicateGame();

				$source = libLab::getGame($LabGame->id);
				$name = isset($_POST['name']) && libLab::trimName($_POST['name']) !== ''
					? $_POST['name']
					: (($source ? $source['name'] : 'Position').' (copy)');

				libLab::registerGame($Copy->id, $name);
				libLab::saveSnapshot($Copy->id, $Copy->getPosition());

				$redirectTo = 'lab.php?gameID='.$Copy->id.'&notice=duplicated';
				break;
			}

			case 'savePosition':
			{
				$LabGame = processLabGame::loadGame($labGameID);

				$labRecord = libLab::getGame($labGameID);
				$name = isset($_POST['name']) && libLab::trimName($_POST['name']) !== ''
					? $_POST['name']
					: ($labRecord ? $labRecord['name'] : '');

				libLab::savePosition($LabGame->getPosition(), $name);

				$redirectTo = 'lab.php?gameID='.$LabGame->id.'&notice=saved';
				break;
			}

			case 'loadPosition':
			{
				$Position = libLab::loadPosition((int)$_POST['positionID']);

				if( $labGameID && isset($_POST['intoCurrent']) && $_POST['intoCurrent'] == '1' )
				{
					$LabGame = processLabGame::loadGame($labGameID);
				}
				else
				{
					$LabGame = processLabGame::createGame($Position->Variant->id);
					libLab::registerGame($LabGame->id, $Position->name);
				}

				$LabGame->setPosition($Position);
				libLab::saveSnapshot($LabGame->id, $Position);
				libLab::touchGame($LabGame->id);

				$redirectTo = 'lab.php?gameID='.$LabGame->id.'&notice=loaded';
				break;
			}

			case 'importPosition':
			{
				$json = isset($_POST['positionJSON']) ? $_POST['positionJSON'] : '';
				if( trim($json) === '' )
					throw new Exception(l_t("No JSON was supplied to import."));

				$Position = LabPosition::fromJSON($json);

				if( $labGameID && isset($_POST['intoCurrent']) && $_POST['intoCurrent'] == '1' )
				{
					$LabGame = processLabGame::loadGame($labGameID);
				}
				else
				{
					$LabGame = processLabGame::createGame($Position->Variant->id);
					libLab::registerGame($LabGame->id, $Position->name !== '' ? $Position->name : 'Imported position');
				}

				$LabGame->setPosition($Position);
				libLab::saveSnapshot($LabGame->id, $Position);
				libLab::touchGame($LabGame->id);

				$redirectTo = 'lab.php?gameID='.$LabGame->id.'&notice=imported';
				break;
			}

			case 'rename':
			{
				processLabGame::loadGame($labGameID);
				libLab::renameGame($labGameID, isset($_POST['name']) ? $_POST['name'] : '');

				$redirectTo = 'lab.php?gameID='.$labGameID.'&notice=renamed';
				break;
			}

			case 'deleteBoard':
			{
				$LabGame = processLabGame::loadGame($labGameID);
				libLab::forgetGame($labGameID);
				$LabGame->deleteGame();

				$redirectTo = 'lab.php?notice=deleted';
				break;
			}

			case 'deleteSavedPosition':
			{
				libLab::deletePosition((int)$_POST['positionID']);

				$redirectTo = $labGameID
					? 'lab.php?gameID='.$labGameID.'&notice=positionDeleted'
					: 'lab.php?notice=positionDeleted';
				break;
			}

			default:
				throw new Exception(l_t("Unknown Lab action."));
		}

		header('Location: '.$redirectTo);
		die();
	}
	catch(Exception $e)
	{
		$labError = $e->getMessage();
	}
}

/**
 * Build a LabPosition from the position editor's form fields.
 *
 * The editor posts the same unit/supply-center assignment state that webDiplomacy's own sandbox
 * creation page uses (javascript/canvasBoard.js), as JSON, along with the year, season and phase
 * chosen in the Lab header.
 *
 * @param processLabGame $LabGame
 * @return LabPosition
 * @throws Exception
 */
function labBuildPositionFromForm($LabGame)
{
	$Position = new LabPosition($LabGame->Variant);

	$Position->turn = LabPosition::turnFromYearSeason(
		isset($_POST['year']) ? (int)$_POST['year'] : 1901,
		isset($_POST['season']) ? $_POST['season'] : 'Spring'
	);
	$Position->phase = LabPosition::normalizePhase(isset($_POST['phase']) ? $_POST['phase'] : 'Diplomacy');

	$state = isset($_POST['positionState']) ? json_decode($_POST['positionState'], true) : null;

	if( !is_array($state) )
		throw new Exception(l_t("The edited position could not be read; please try again."));

	foreach($state as $slot)
	{
		$slot = (array)$slot;

		$countryID = isset($slot['countryID']) ? (int)$slot['countryID'] : -1;
		if( $countryID < 1 ) continue;

		$unitTerrID = isset($slot['unitPositionTerrID']) ? (int)$slot['unitPositionTerrID'] : -1;
		$unitType = isset($slot['unitType']) ? $slot['unitType'] : '';

		if( $unitTerrID > 0 && ($unitType == 'Army' || $unitType == 'Fleet') )
			$Position->units[] = array('countryID'=>$countryID, 'type'=>$unitType, 'terrID'=>$unitTerrID);

		$scTerrID = isset($slot['unitSCTerrID']) ? (int)$slot['unitSCTerrID'] : -1;

		if( $scTerrID > 0 )
			$Position->centers[] = array('countryID'=>$countryID, 'terrID'=>$scTerrID);
	}

	// Validate here so a bad position is reported on the editor rather than half-written
	$Position->validate();

	return $Position;
}

/**
 * Convert a LabPosition into the assignment-slot format that canvasBoard.js edits.
 *
 * Each slot holds at most one unit and at most one supply center for a single country, so units
 * are laid out first and supply centers are then packed into that country's existing slots where
 * possible, exactly as webDiplomacy's own getDefaultOptions() does.
 *
 * @param LabPosition $Position
 * @return array
 */
function labPositionToEditorState(LabPosition $Position)
{
	$state = array();
	$territories = $Position->territories();

	$slotsByCountry = array();

	foreach($Position->units as $unit)
	{
		$index = count($state);
		$terrID = (int)$unit['terrID'];

		$state[] = array(
			'index' => $index,
			'countryID' => (int)$unit['countryID'],
			'unitPositionTerrID' => $terrID,
			'unitPositionTerrIDParent' => $Position->provinceID($terrID),
			'unitSCTerrID' => -1,
			'unitType' => $unit['type']
		);

		$slotsByCountry[(int)$unit['countryID']][] = $index;
	}

	foreach($Position->centers as $center)
	{
		$countryID = (int)$center['countryID'];
		$terrID = (int)$center['terrID'];

		// Prefer a slot this country already has that is not yet carrying a supply center
		$placed = false;
		if( isset($slotsByCountry[$countryID]) )
		{
			foreach($slotsByCountry[$countryID] as $index)
			{
				if( $state[$index]['unitSCTerrID'] == -1 )
				{
					$state[$index]['unitSCTerrID'] = $terrID;
					$placed = true;
					break;
				}
			}
		}

		if( !$placed )
		{
			$index = count($state);
			$state[] = array(
				'index' => $index,
				'countryID' => $countryID,
				'unitPositionTerrID' => -1,
				'unitPositionTerrIDParent' => -1,
				'unitSCTerrID' => $terrID,
				'unitType' => null
			);
			$slotsByCountry[$countryID][] = $index;
		}
	}

	return $state;
}

libHTML::starthtml();

if( isset($_REQUEST['notice']) )
{
	$notices = array(
		'created' => l_t('New position created. Place your units, then switch to Orders.'),
		'positionSet' => l_t('Position updated.'),
		'turnSet' => l_t('Year, season and phase updated.'),
		'resolved' => l_t('Orders adjudicated. Use RESET to return to the position before these orders.'),
		'reset' => l_t('Returned to the position from before the last adjudication.'),
		'duplicated' => l_t('Position duplicated; you are now working on the copy.'),
		'saved' => l_t('Position saved.'),
		'loaded' => l_t('Saved position loaded.'),
		'imported' => l_t('Position imported.'),
		'renamed' => l_t('Position renamed.'),
		'deleted' => l_t('Position deleted.'),
		'positionDeleted' => l_t('Saved position deleted.')
	);

	if( isset($notices[$_REQUEST['notice']]) ) $labNotice = $notices[$_REQUEST['notice']];
}

require_once(l_r('locales/English/lab.php'));

libHTML::footer();
?>
