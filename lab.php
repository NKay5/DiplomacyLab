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
 * The board is the application. This file is only the way in: it opens the board on the scenario
 * being worked on, starts a new one, or hands a position over as JSON. Everything the Lab does -
 * building a position, ordering every power, adjudicating, stepping back through the analysis and
 * branching off it - happens on the board itself, through the lab/* API entries in api.php.
 *
 * Board state is written in exactly two places: LabPosition (objects/labPosition.php) applies a
 * position to a game, and libLabTree (lib/labTree.php) records where each position sits in the
 * analysis. Adjudication is webDiplomacy's own, unmodified.
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
require_once(l_r('lib/labTree.php'));

if( $Misc->Panic )
	libHTML::notice(l_t('Diplomacy Lab disabled'),
		l_t("Diplomacy Lab has been temporarily disabled while an unexpected problem is taken care of. Please try again later."));

if( !$User->type['User'] )
	libHTML::notice(l_t('Not logged on'),
		l_t("Only a logged on user can use Diplomacy Lab. Please <a href='logon.php' class='light'>log on</a> first."));

libLab::ensureTables();
libLabTree::ensureTables();

$labGameID = isset($_REQUEST['gameID']) ? (int)$_REQUEST['gameID'] : 0;

/*
 * Exporting is handled before any HTML is produced, so the browser gets plain JSON rather than a
 * page with JSON in it.
 */
if( isset($_REQUEST['export']) && $labGameID )
{
	try
	{
		$LabGame = processLabGame::loadGame($labGameID);
		$Position = $LabGame->getPosition();

		$context = libLabTree::contextOf($labGameID);
		if( $context ) $Position->name = $context['scenarioName'].' - '.$context['branchName'];

		header('Content-Type: application/json; charset=utf-8');
		header('Content-Disposition: attachment; filename="diplomacy-lab-position.json"');
		print $Position->toJSON();
		die();
	}
	catch(Exception $e)
	{
		libHTML::error($e->getMessage());
	}
}

/*
 * Everything else is a way of arriving at a board.
 */
try
{
	if( isset($_REQUEST['newBoard']) || isset($_REQUEST['new']) )
	{
		$name = isset($_REQUEST['name']) ? $_REQUEST['name'] : '';
		$context = libLabTree::createScenario($name);

		header('Location: '.libLab::boardURL($context['gameID']));
		die();
	}

	if( $labGameID )
	{
		// Check it is this user's before sending them to it.
		processLabGame::loadGame($labGameID);

		header('Location: '.libLab::boardURL($labGameID));
		die();
	}

	header('Location: '.libLab::boardURL(libLabTree::currentOrNewGameID()));
	die();
}
catch(Exception $e)
{
	libHTML::error($e->getMessage());
}
