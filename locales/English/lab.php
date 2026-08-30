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
 * The Diplomacy Lab interface.
 *
 * The position editor here is webDiplomacy's own sandbox map editor (javascript/canvasBoard.js,
 * as used by gamecreateSandbox.php), and the Orders view is webDiplomacy's own board page shown
 * in a frame, so the map, units, colours, arrows, order entry and results are all unchanged.
 *
 * @package Base
 * @subpackage Lab
 */

$labToken = libAuth::formTokenHTML();

if( $labError )
	print '<div class="content"><p class="notice">'.$labError.'</p></div>';
elseif( $labNotice )
	print '<div class="content"><p class="notice">'.$labNotice.'</p></div>';

$labBoards = libLab::listGames();
$labSavedPositions = libLab::listPositions();

if( !$labGameID )
{
	/*
	 * The Lab home page: pick up an existing board, start a new one, or bring a position in from
	 * a saved position or from JSON.
	 */
	?>
	<div class="content-bare content-board-header content-title-header">
		<div class="pageTitle barAlt1">DIPLOMACY LAB</div>
		<div class="pageDescription">A free analysis board. Put the pieces anywhere, give every power its orders, adjudicate, and step straight back.</div>
	</div>
	<div class="content content-follow-on">
		<form method="post" style="display:inline">
			<?php print $labToken; ?>
			<input type="hidden" name="labAction" value="new" />
			<strong>New position:</strong>
			<input class="gameCreate" type="text" name="name" size="28" placeholder="Name (optional)" />
			<select class="gameCreate" name="variantID">
			<?php
				foreach(Config::$variants as $variantID=>$variantName)
				{
					if( $variantID == 57 || $variantID == 70 ) continue; // Not supported by the map editor
					print '<option value="'.$variantID.'"'.($variantID == 1 ? ' selected' : '').'>'.$variantName.'</option>';
				}
			?>
			</select>
			<input class="green-Submit" type="submit" value="New position" />
		</form>

		<div class="hr"></div>

		<strong>Your positions</strong>
		<?php
		if( count($labBoards) == 0 )
			print '<p>No positions yet. Create one above.</p>';
		else
		{
			print '<table class="hof"><tr><th>Name</th><th>Turn</th><th></th></tr>';
			foreach($labBoards as $board)
			{
				$Variant = libVariant::loadFromVariantID($board['variantID']);
				print '<tr class="hof">'.
					'<td class="hof"><a href="lab.php?gameID='.$board['gameID'].'">'.htmlentities($board['name']).'</a></td>'.
					'<td class="hof">'.$Variant->turnAsDate($board['turn']).', '.$board['phase'].'</td>'.
					'<td class="hof"><a href="lab.php?gameID='.$board['gameID'].'&amp;mode=edit">Edit position</a></td>'.
					'</tr>';
			}
			print '</table>';
		}
		?>

		<div class="hr"></div>

		<strong>Saved positions</strong>
		<?php
		if( count($labSavedPositions) == 0 )
			print '<p>Nothing saved yet. Save the board you are working on to keep it here.</p>';
		else
		{
			print '<table class="hof"><tr><th>Name</th><th>Saved</th><th></th></tr>';
			foreach($labSavedPositions as $saved)
			{
				print '<tr class="hof">'.
					'<td class="hof">'.htmlentities($saved['name']).'</td>'.
					'<td class="hof">'.date('Y-m-d H:i', $saved['timeSaved']).'</td>'.
					'<td class="hof">'.
						'<form method="post" style="display:inline">'.$labToken.
						'<input type="hidden" name="labAction" value="loadPosition" />'.
						'<input type="hidden" name="positionID" value="'.$saved['id'].'" />'.
						'<input type="submit" class="form-submit" value="Open in a new position" />'.
						'</form> '.
						'<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this saved position?\');">'.$labToken.
						'<input type="hidden" name="labAction" value="deleteSavedPosition" />'.
						'<input type="hidden" name="positionID" value="'.$saved['id'].'" />'.
						'<input type="submit" class="form-submit" value="Delete" />'.
						'</form>'.
					'</td>'.
					'</tr>';
			}
			print '</table>';
		}
		?>

		<div class="hr"></div>

		<strong>Import a position (JSON)</strong>
		<form method="post">
			<?php print $labToken; ?>
			<input type="hidden" name="labAction" value="importPosition" />
			<p><textarea name="positionJSON" rows="8" style="width:100%" placeholder='{"year":1903,"season":"Fall","phase":"Diplomacy","units":[{"country":"France","type":"Army","territory":"Picardy"}]}'></textarea></p>
			<p><input class="form-submit" type="submit" value="Import" /></p>
		</form>
	</div>
	<?php

	return;
}

/*
 * A single Lab board. The header carries everything you can do to the position; below it is
 * either the position editor or webDiplomacy's own board.
 */
$LabGame = null;
try
{
	$LabGame = processLabGame::loadGame($labGameID);
}
catch(Exception $e)
{
	libHTML::notice(l_t('Position not available'), $e->getMessage());
}

libLab::touchGame($labGameID);

$labRecord = libLab::getGame($labGameID);
$labName = $labRecord ? $labRecord['name'] : $LabGame->name;
$labHasSnapshot = ($labRecord && !is_null($labRecord['snapshotJSON']) && $labRecord['snapshotJSON'] !== '');

$Position = $LabGame->getPosition();
$labMode = ( isset($_REQUEST['mode']) && $_REQUEST['mode'] == 'edit' ) ? 'edit' : 'orders';

$labYear = $Position->year();
$labSeason = $Position->season();
$labPhase = $Position->phase;

// The years offered in the dropdown: a generous range around the position's own year
$labYearFrom = 1901;
$labYearTo = max(1950, $labYear + 10);
?>
<div class="content-bare content-board-header content-title-header">
	<div class="pageTitle barAlt1">DIPLOMACY LAB</div>
	<div class="pageDescription"><?php print htmlentities($labName); ?> &mdash; <?php print $LabGame->Variant->name; ?></div>
</div>
<div class="content content-follow-on">

	<div style="margin-bottom:8px">
		<a href="lab.php" class="light">&laquo; All positions</a>
	</div>

	<!-- Position level actions -->
	<div style="margin-bottom:10px">
		<form method="post" style="display:inline">
			<?php print $labToken; ?>
			<input type="hidden" name="gameID" value="<?php print $labGameID; ?>" />
			<input type="hidden" name="labAction" value="duplicate" />
			<input class="form-submit" type="submit" value="Duplicate" title="Create an independent copy of this position, so variations can be explored side by side" />
		</form>
		<form method="post" style="display:inline">
			<?php print $labToken; ?>
			<input type="hidden" name="gameID" value="<?php print $labGameID; ?>" />
			<input type="hidden" name="labAction" value="savePosition" />
			<input class="gameCreate" type="text" name="name" size="20" placeholder="Save as..." />
			<input class="form-submit" type="submit" value="Save" />
		</form>
		<a class="form-submit" style="padding:3px 8px" href="lab.php?gameID=<?php print $labGameID; ?>&amp;export=1">Export JSON</a>
		<form method="post" style="display:inline" onsubmit="return confirm('Delete this position for good?');">
			<?php print $labToken; ?>
			<input type="hidden" name="gameID" value="<?php print $labGameID; ?>" />
			<input type="hidden" name="labAction" value="deleteBoard" />
			<input class="form-submit" type="submit" value="Delete" />
		</form>
	</div>

	<!-- Mode switch -->
	<div style="margin-bottom:10px">
		<a class="form-submit" style="padding:4px 10px;<?php print $labMode=='edit' ? 'font-weight:bold' : ''; ?>"
			href="lab.php?gameID=<?php print $labGameID; ?>&amp;mode=edit">EDIT POSITION</a>
		<a class="form-submit" style="padding:4px 10px;<?php print $labMode=='orders' ? 'font-weight:bold' : ''; ?>"
			href="lab.php?gameID=<?php print $labGameID; ?>&amp;mode=orders">ORDERS</a>
		<span style="margin-left:14px">
			<strong><?php print $LabGame->Variant->turnAsDate($LabGame->turn); ?>, <?php print $LabGame->phase; ?></strong>
		</span>
	</div>

<?php if( $labMode == 'edit' ) { ?>

	<form method="post" id="labEditForm">
		<?php print $labToken; ?>
		<input type="hidden" name="gameID" value="<?php print $labGameID; ?>" />
		<input type="hidden" name="labAction" value="applyPosition" />
		<input type="hidden" name="positionState" id="positionState" value="" />

		<!-- Year / season / phase -->
		<div style="margin-bottom:10px">
			<strong>Year:</strong>
			<select class="gameCreate" name="year">
			<?php
				for($y = $labYearFrom; $y <= $labYearTo; $y++)
					print '<option value="'.$y.'"'.($y == $labYear ? ' selected' : '').'>'.$y.'</option>';
			?>
			</select>
			<strong>Season:</strong>
			<select class="gameCreate" name="season">
				<option value="Spring"<?php print $labSeason=='Spring' ? ' selected' : ''; ?>>Spring</option>
				<option value="Fall"<?php print $labSeason=='Fall' ? ' selected' : ''; ?>>Fall</option>
			</select>
			<strong>Phase:</strong>
			<select class="gameCreate" name="phase">
				<option value="Diplomacy"<?php print $labPhase=='Diplomacy' ? ' selected' : ''; ?>>Diplomacy</option>
				<option value="Retreats"<?php print $labPhase=='Retreats' ? ' selected' : ''; ?>>Retreat</option>
				<option value="Builds"<?php print $labPhase=='Builds' ? ' selected' : ''; ?>>Adjustment</option>
			</select>
		</div>

		<p>
			Click a province to place a unit and/or claim its supply center for the selected power.
			Click it again with <em>None</em> selected to empty it. Clicking a coastal province that already
			holds that power's army places a fleet instead. Sea provinces take fleets only, inland provinces
			armies only, and a province holds at most one unit &mdash; but nothing else is enforced: powers may
			have no units at all, unit counts need not match supply centers, and supply centers may be left neutral.
		</p>

		<canvas id="boardCanvasBase" style="display:none"></canvas>
		<canvas id="boardCanvasOptions" style="display:none"></canvas>
		<div style="text-align:center">
			<canvas id="boardCanvas"></canvas>
		</div>

		<strong>Set country:</strong><br />
		<div id="customUnitCountrySelect"></div>

		<strong>Set mode:</strong><br />
		<div id="customUnitAssignSelect">
			<table style="text-align:center">
				<tr>
					<td id="assignUnitAndSC" class="selectedMode" style="border: 1px solid black; background-color:#ddd">Unit and Supply center</td>
					<td id="assignUnit" style="border: 1px solid black; background-color:#ddd">Unit only</td>
					<td id="assignSC" style="border: 1px solid black; background-color:#ddd">Supply center only</td>
				</tr>
			</table>
		</div>
		<style>
			.selectedMode {
				border: 3px solid black !important;
				font-weight:bold;
			}
		</style>

		<p>
			<input class="green-Submit" type="submit" value="APPLY POSITION" />
			<input class="form-submit" type="button" id="labClearBoard" value="Clear the board" />
			<input class="form-submit" type="button" id="labStandardStart" value="Standard 1901 start" />
		</p>

		<strong>Assignments:</strong>
		<div id="labAssignments"></div>
	</form>

	<script>
		// Contains the variant map data, colours and default assignments used by canvasBoard.js
		let canvasBoardConfigJS = {};
		<?php
			// Only the variant this position uses needs to be sent
			print 'canvasBoardConfigJS['.$LabGame->Variant->id.'] = '.$LabGame->Variant->canvasBoardConfigJS().';';
		?>
	</script>

	<script>
		// The position currently on the board, in the assignment-slot format canvasBoard.js works with.
		const labInitialState = <?php print json_encode(labPositionToEditorState($Position)); ?>;
		const labVariantID = <?php print (int)$LabGame->Variant->id; ?>;

		// canvasBoard.js caps how many units can be placed at the number of slots it is given, so the
		// Lab hands it plenty: a position here is not limited to one unit per supply center.
		const labSlotCount = <?php print max(120, count($Position->units) + count($Position->centers) + 40); ?>;

		function labMakeEmptyState() {
			let state = [];
			for(let i = 0; i < labSlotCount; i++)
				state.push({
					index: i,
					countryID: -1,
					unitPositionTerrID: -1,
					unitPositionTerrIDParent: -1,
					unitSCTerrID: -1,
					unitType: null
				});
			return state;
		}

		// Take a saved set of slots and pad it out to the full slot count, so there is always room
		// to place more units.
		function labExpandState(slots) {
			let state = labMakeEmptyState();
			for(let i = 0; i < slots.length && i < state.length; i++) {
				state[i].countryID = slots[i].countryID;
				state[i].unitPositionTerrID = slots[i].unitPositionTerrID;
				state[i].unitPositionTerrIDParent = slots[i].unitPositionTerrIDParent;
				state[i].unitSCTerrID = slots[i].unitSCTerrID;
				state[i].unitType = slots[i].unitType;
			}
			return state;
		}

		// The Lab renders its own assignments table rather than the fixed-size one canvasBoard.js
		// expects, because a Lab position can hold more units than there are supply centers.
		function labDrawAssignments() {
			const countryNamesByID = canvasBoardConfigJS[labVariantID].getCountryNamesByID();
			let rows = '';

			for(const slot of currentUnitSCState) {
				if( slot.countryID <= 0 ) continue;

				const unitName = ( typeof Territories !== 'undefined' && Territories[slot.unitPositionTerrID] )
					? Territories[slot.unitPositionTerrID].name : '';
				const scName = ( typeof Territories !== 'undefined' && Territories[slot.unitSCTerrID] )
					? Territories[slot.unitSCTerrID].name : '';

				if( unitName === '' && scName === '' ) continue;

				rows += '<tr class="hof">'
					+ '<td class="hof country' + slot.countryID + '">' + countryNamesByID[slot.countryID-1] + '</td>'
					+ '<td class="hof">' + (slot.unitType ? slot.unitType : '') + '</td>'
					+ '<td class="hof">' + unitName + '</td>'
					+ '<td class="hof">' + scName + '</td>'
					+ '</tr>';
			}

			if( rows === '' )
				rows = '<tr class="hof"><td class="hof" colspan="4">The board is empty.</td></tr>';

			document.getElementById('labAssignments').innerHTML =
				'<table class="hof variant<?php print $LabGame->Variant->name; ?>">'
				+ '<tr><th>Country</th><th>Unit type</th><th>Unit position</th><th>Supply center</th></tr>'
				+ rows + '</table>';
		}

		// Write the current placement into the form so it is posted with the year/season/phase
		function labSaveState() {
			document.getElementById('positionState').value = JSON.stringify(currentUnitSCState);
		}

		function labSetupCountryButtons() {
			const countryNamesByID = canvasBoardConfigJS[labVariantID].getCountryNamesByID();

			let table = '<table><tr>';
			let countryID = 0;
			for (const color of countryColors) {
				const countryName = countryID == 0 ? 'None' : countryNamesByID[countryID-1];
				table += '<td ' + (countryID == 0 ? 'class="selectedMode"' : '')
					+ ' style="background-color:rgb(' + color[0] + ',' + color[1] + ',' + color[2] + ');'
					+ ' width: 20px; height: 20px; border: 1px solid black; cursor:pointer;"'
					+ ' id="countrySelection' + countryID + '">' + countryName + '</td>';
				countryID++;
			}
			table += '</tr></table>';

			document.getElementById('customUnitCountrySelect').innerHTML = table;

			countryID = 0;
			for (const color of countryColors) {
				const localCountryID = countryID;
				const countryElement = document.getElementById('countrySelection' + countryID);
				countryElement.onclick = function() {
					countryElement.classList.add('selectedMode');
					const previous = document.getElementById('countrySelection' + assigningCountryID);
					if( previous ) previous.classList.remove('selectedMode');
					assigningCountryID = localCountryID;
				};
				countryID++;
			}
		}

		// Assignment mode buttons, as on webDiplomacy's own sandbox creation page
		document.getElementById('assignUnitAndSC').onclick = function() {
			assignmentMode = 0;
			document.getElementById('assignUnitAndSC').className = 'selectedMode';
			document.getElementById('assignUnit').className = '';
			document.getElementById('assignSC').className = '';
		};
		document.getElementById('assignUnit').onclick = function() {
			assignmentMode = 1;
			document.getElementById('assignUnitAndSC').className = '';
			document.getElementById('assignUnit').className = 'selectedMode';
			document.getElementById('assignSC').className = '';
		};
		document.getElementById('assignSC').onclick = function() {
			assignmentMode = 2;
			document.getElementById('assignUnitAndSC').className = '';
			document.getElementById('assignUnit').className = '';
			document.getElementById('assignSC').className = 'selectedMode';
		};

		document.getElementById('labClearBoard').onclick = function() {
			currentUnitSCState = labMakeEmptyState();
			drawMap();
			labDrawAssignments();
			labSaveState();
		};

		document.getElementById('labStandardStart').onclick = function() {
			currentUnitSCState = labExpandState(canvasBoardConfigJS[labVariantID].getDefaultOptions());
			drawMap();
			labDrawAssignments();
			labSaveState();
		};

		function labOnVariantLoaded() {
			labSetupCountryButtons();
			labDrawAssignments();
			labSaveState();
		}

		function initializeLabEditor() {
			variantID = labVariantID;

			// Seed the editor with the position that is on the board. This must happen before
			// loadVariant(), which fills an empty state with the variant's default 1901 setup;
			// in the Lab an empty board has to stay empty.
			currentUnitSCState = labExpandState(labInitialState);

			canvasElement.addEventListener('click', (event) => {
				applyAssignment();
				drawMap();
				labDrawAssignments();
				labSaveState();
			});

			loadVariant(labOnVariantLoaded);
		}
	</script>

	<?php
	libHTML::$footerIncludes[] = l_j('canvasBoard.js');
	libHTML::$footerScript[] = 'initializeLabEditor();';
	?>

<?php } else { ?>

	<!--
		The Orders view is webDiplomacy's own board, unchanged. Because this is a sandbox game its
		order interface already shows every power's orders together (board/orders/orderinterface.php),
		with webDiplomacy's usual move arrows, supports, convoys and results on the map.
	-->
	<p>
		Enter the orders for every power below, then press RESOLVE. Orders are entered with
		webDiplomacy's own interface, and adjudicated by webDiplomacy's own adjudicator.
		Use RESOLVE rather than the board's own Ready button: only RESOLVE records the position
		RESET comes back to.
	</p>

	<!-- Year / season / phase, without having to open the position editor -->
	<form method="post" style="margin-bottom:10px">
		<?php print $labToken; ?>
		<input type="hidden" name="gameID" value="<?php print $labGameID; ?>" />
		<input type="hidden" name="labAction" value="setTurn" />
		<strong>Year:</strong>
		<select class="gameCreate" name="year">
		<?php
			for($y = $labYearFrom; $y <= $labYearTo; $y++)
				print '<option value="'.$y.'"'.($y == $labYear ? ' selected' : '').'>'.$y.'</option>';
		?>
		</select>
		<strong>Season:</strong>
		<select class="gameCreate" name="season">
			<option value="Spring"<?php print $labSeason=='Spring' ? ' selected' : ''; ?>>Spring</option>
			<option value="Fall"<?php print $labSeason=='Fall' ? ' selected' : ''; ?>>Fall</option>
		</select>
		<strong>Phase:</strong>
		<select class="gameCreate" name="phase">
			<option value="Diplomacy"<?php print $labPhase=='Diplomacy' ? ' selected' : ''; ?>>Diplomacy</option>
			<option value="Retreats"<?php print $labPhase=='Retreats' ? ' selected' : ''; ?>>Retreat</option>
			<option value="Builds"<?php print $labPhase=='Builds' ? ' selected' : ''; ?>>Adjustment</option>
		</select>
		<input class="form-submit" type="submit" value="Set" title="Move the calendar without moving the pieces" />
	</form>

	<div style="margin-bottom:10px">
		<form method="post" style="display:inline">
			<?php print $labToken; ?>
			<input type="hidden" name="gameID" value="<?php print $labGameID; ?>" />
			<input type="hidden" name="labAction" value="resolve" />
			<input class="green-Submit" type="submit" value="RESOLVE"
				title="Ready every power and adjudicate immediately" />
		</form>
		<form method="post" style="display:inline">
			<?php print $labToken; ?>
			<input type="hidden" name="gameID" value="<?php print $labGameID; ?>" />
			<input type="hidden" name="labAction" value="reset" />
			<input class="form-submit" type="submit" value="RESET"
				title="Go back to the position as it was before the last adjudication"
				<?php print $labHasSnapshot ? '' : 'disabled'; ?> />
		</form>
		<a class="light" style="margin-left:12px" target="_blank"
			href="<?php print libLab::boardURL($labGameID); ?>">Open the board in its own tab</a>
	</div>

	<iframe id="labBoardFrame" src="<?php print libLab::boardURL($labGameID); ?>"
		onload="labScrollFrameToBoard()"
		style="width:100%; height:1600px; border:1px solid #999; background:#fff"></iframe>

	<script>
		// Scroll the embedded board past webDiplomacy's site header to the board itself, using the
		// board page's own #gamePanel anchor. This is done inside the frame rather than by putting
		// the anchor in its URL, because a fragment in the frame's URL scrolls the whole Lab page
		// and pushes the RESOLVE and RESET buttons out of view.
		function labScrollFrameToBoard() {
			try {
				const frame = document.getElementById('labBoardFrame');
				const frameDoc = frame.contentDocument;
				if( !frameDoc ) return;

				// The Lab has one person playing every power, so the diplomatic chatbox is just in
				// the way. It is hidden here rather than removed from the board, so the board is
				// untouched and still complete when opened in its own tab.
				// div.chatbox is used only by the chatbox; the order table's wrapper is .chatWrapper.
				const hideChat = frameDoc.createElement('style');
				hideChat.textContent = '#chatboxtabs, #chatboxscroll, div.chatbox { display: none !important; }';
				frameDoc.head.appendChild(hideChat);

				const anchor = frameDoc.getElementsByName('gamePanel')[0];
				if( !anchor ) return;

				frame.contentWindow.scrollTo(0,
					anchor.getBoundingClientRect().top + frame.contentWindow.scrollY);
			}
			catch(e) {
				// Different origin or the board is still loading; leaving the frame at the top is fine
			}
		}
	</script>

<?php } ?>

</div>
