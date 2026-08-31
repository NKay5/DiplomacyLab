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
 * A Diplomacy Lab position: an arbitrary, freely editable board state.
 *
 * This is the single canonical representation of a position in Diplomacy Lab. Every Lab feature
 * (new position, edit position, reset, duplicate, save, load, JSON import/export, and the
 * headless adjudication API) is expressed as either reading a LabPosition out of a game or
 * applying one to a game, so there is exactly one code path that writes board state.
 *
 * A Lab position is deliberately *not* required to be reachable in a real game: powers may have
 * zero units, unit counts need not match supply center counts, and supply centers may be left
 * neutral. Only the geographic rules of the map are enforced (no armies at sea, no fleets in
 * landlocked provinces, at most one unit per province, coasts handled correctly), because those
 * are the rules the adjudicator itself relies on.
 *
 * @package Base
 * @subpackage Lab
 */
class LabPosition
{
	/**
	 * How far into the future a Lab board's process time is pushed, in seconds.
	 *
	 * The gamemaster only selects games whose processTime has passed (gamemaster.php), and
	 * processGame::process() resets processTime to one phase length after every adjudication. A
	 * Lab board must not quietly adjudicate itself a day after you last looked at it, so its clock
	 * is pushed ten years out whenever the board changes.
	 *
	 * @var int
	 */
	const CLOCK_FREEZE_SECONDS = 315360000;

	/**
	 * The variant this position belongs to.
	 * @var WDVariant
	 */
	public $Variant;

	/**
	 * A human readable name for the position.
	 * @var string
	 */
	public $name = '';

	/**
	 * The in-game turn number. For Classic: turn = (year-1901)*2 + (Autumn ? 1 : 0).
	 * @var int
	 */
	public $turn = 0;

	/**
	 * One of 'Diplomacy', 'Retreats', 'Builds'.
	 * @var string
	 */
	public $phase = 'Diplomacy';

	/**
	 * Units in the position.
	 * @var array list of array('countryID'=>int, 'type'=>'Army'|'Fleet', 'terrID'=>int)
	 */
	public $units = array();

	/**
	 * Supply center ownership. Supply centers not listed here are neutral.
	 * @var array list of array('countryID'=>int, 'terrID'=>int)
	 */
	public $centers = array();

	/*
	 * The three properties below are the engine state: everything webDiplomacy needs beyond the
	 * units and supply centers to carry on from exactly where it was. They are filled in when a
	 * position is read out of a live game and are what makes a snapshot of a Retreats or Builds
	 * phase restorable; a position built by hand in the editor has none of them.
	 *
	 * Units are referred to by their index in $units rather than by wD_Units.id, for two reasons:
	 * the IDs change when the position is written into a game, and during a Retreats phase two
	 * units legitimately share one territory (the dislodged unit stays put while the unit that
	 * dislodged it moves in), so no combination of country, type and territory identifies a unit.
	 */

	/**
	 * The wD_TerrStatus rows, which carry who owns each territory and, during a Retreats phase,
	 * which unit is dislodged and where it may go.
	 *
	 * @var array list of array('terrID'=>int, 'countryID'=>int, 'standoff'=>bool,
	 *                          'occupiedFromTerrID'=>int|null, 'occupyingUnit'=>int|null,
	 *                          'retreatingUnit'=>int|null)
	 */
	public $territoryStatus = array();

	/**
	 * The orders as they stood, whether or not they had been filled in.
	 *
	 * @var array list of array('countryID'=>int, 'type'=>string, 'unit'=>int|null,
	 *                          'toTerrID'=>int|null, 'fromTerrID'=>int|null, 'viaConvoy'=>string)
	 */
	public $orders = array();

	/**
	 * Each power's standing: the unit and supply center counts a Builds phase is derived from,
	 * and the status the game holds them in.
	 *
	 * @var array list of array('countryID'=>int, 'status'=>string, 'orderStatus'=>string,
	 *                          'supplyCenterNo'=>int, 'unitNo'=>int)
	 */
	public $members = array();

	/**
	 * Territory metadata for the variant's map, keyed by territory ID.
	 * @var array array[$terrID] = array('id','name','type','supply','coast','coastParentID')
	 */
	private $territories;

	/**
	 * Territory IDs keyed by lowercased territory name.
	 * @var array array[$lowercaseName] = $terrID
	 */
	private $terrIDByName;

	public function __construct($Variant)
	{
		$this->Variant = $Variant;
		$this->loadTerritories();
	}

	/**
	 * Load the variant's territory metadata from the database. Everything the Lab needs to know
	 * about the map geometry comes from here, so the Lab automatically follows whatever the
	 * variant's own install script defined.
	 */
	private function loadTerritories()
	{
		global $DB;

		$this->territories = array();
		$this->terrIDByName = array();

		$tabl = $DB->sql_tabl("SELECT id, name, type, supply, coast, coastParentID
			FROM wD_Territories WHERE mapID = ".$this->Variant->mapID);

		while( $row = $DB->tabl_hash($tabl) )
		{
			$row['id'] = (int)$row['id'];
			$row['coastParentID'] = (int)$row['coastParentID'];
			$this->territories[$row['id']] = $row;
			$this->terrIDByName[strtolower($row['name'])] = $row['id'];
		}

		if( count($this->territories) == 0 )
			throw new Exception("No territories found for this variant's map; the variant data may need to be regenerated via admincp.php.");
	}

	public function territories() { return $this->territories; }

	/**
	 * The territory ID for a territory name, or false. Names are matched case-insensitively, and
	 * coasts are named as the variant names them, e.g. 'Spain (North Coast)'.
	 *
	 * @param string $name
	 * @return int|false
	 */
	public function terrIDByName($name)
	{
		$name = strtolower(trim($name));
		return isset($this->terrIDByName[$name]) ? $this->terrIDByName[$name] : false;
	}

	/**
	 * The province (coast-parent) territory ID for a territory ID. Coast children resolve to their
	 * parent so that 'one unit per province' can be enforced across coasts.
	 *
	 * @param int $terrID
	 * @return int
	 */
	public function provinceID($terrID)
	{
		$terrID = (int)$terrID;
		if( !isset($this->territories[$terrID]) ) return $terrID;

		if( $this->territories[$terrID]['coast'] == 'Child' )
			return $this->territories[$terrID]['coastParentID'];

		return $terrID;
	}

	/**
	 * Whether the given territory can hold a unit of the given type. This mirrors the checks
	 * webDiplomacy already applies to custom sandbox unit assignments in gamecreateSandbox.php.
	 *
	 * @param int $terrID
	 * @param string $unitType 'Army' or 'Fleet'
	 * @return bool
	 */
	public function canHoldUnit($terrID, $unitType)
	{
		if( !isset($this->territories[$terrID]) ) return false;
		$t = $this->territories[$terrID];

		if( $unitType == 'Fleet' )
			return ( $t['type'] == 'Sea' || ( $t['type'] == 'Coast' && in_array($t['coast'], array('No','Child')) ) );

		return ( $t['type'] == 'Land' || ( $t['type'] == 'Coast' && in_array($t['coast'], array('No','Parent')) ) );
	}

	/**
	 * Whether the given territory is a supply center.
	 *
	 * @param int $terrID
	 * @return bool
	 */
	public function isSupplyCenter($terrID)
	{
		return ( isset($this->territories[$terrID]) && $this->territories[$terrID]['supply'] == 'Yes' );
	}

	/**
	 * The number of countries in this variant.
	 * @return int
	 */
	public function countryCount()
	{
		return count($this->Variant->countries);
	}

	/**
	 * The country ID for a country name, or false. Country names are matched case-insensitively.
	 *
	 * @param string $name
	 * @return int|false
	 */
	public function countryIDByName($name)
	{
		$name = strtolower(trim($name));
		foreach($this->Variant->countries as $index=>$countryName)
			if( strtolower($countryName) == $name ) return $index+1;

		return false;
	}

	/**
	 * The country name for a country ID, or 'Neutral' for 0.
	 *
	 * @param int $countryID
	 * @return string
	 */
	public function countryNameByID($countryID)
	{
		$countryID = (int)$countryID;
		if( $countryID < 1 || $countryID > $this->countryCount() ) return 'Neutral';

		return $this->Variant->countries[$countryID-1];
	}

	// --- Year / season conversion -------------------------------------------------------------

	/**
	 * The year this position's turn falls in.
	 * @return int
	 */
	public function year() { return (int)floor($this->turn/2) + 1901; }

	/**
	 * 'Spring' or 'Fall'.
	 * @return string
	 */
	public function season() { return ($this->turn % 2) ? 'Fall' : 'Spring'; }

	/**
	 * Convert a year and season into a webDiplomacy turn number.
	 *
	 * @param int $year
	 * @param string $season 'Spring', or any of 'Fall'/'Autumn'
	 * @return int
	 */
	public static function turnFromYearSeason($year, $season)
	{
		$year = (int)$year;
		if( $year < 1901 ) $year = 1901;

		$isAutumn = in_array(strtolower(trim($season)), array('fall','autumn'));

		return ($year - 1901)*2 + ($isAutumn ? 1 : 0);
	}

	/**
	 * Normalize a phase name, accepting the names used on the Lab UI as well as webDiplomacy's own.
	 *
	 * @param string $phase
	 * @return string one of 'Diplomacy', 'Retreats', 'Builds'
	 */
	public static function normalizePhase($phase)
	{
		switch(strtolower(trim($phase)))
		{
			case 'retreat':
			case 'retreats':
				return 'Retreats';
			case 'build':
			case 'builds':
			case 'adjustment':
			case 'adjustments':
				return 'Builds';
			default:
				return 'Diplomacy';
		}
	}

	// --- Validation ---------------------------------------------------------------------------

	/**
	 * Whether this position carries the engine state of a live game, as opposed to being a
	 * position someone built in the editor.
	 *
	 * @return bool
	 */
	public function hasEngineState()
	{
		return count($this->territoryStatus) > 0;
	}

	/**
	 * Forget the engine state, leaving the units, supply centers and calendar.
	 *
	 * Editing a position by hand invalidates everything the engine had worked out about it: which
	 * unit occupies a territory, which unit is dislodged and where it may retreat to, and the
	 * orders that were entered. Rather than carry state that no longer describes the position, the
	 * editor drops it, and the position is written back as though it had been built by hand.
	 */
	public function clearEngineState()
	{
		$this->territoryStatus = array();
		$this->orders = array();
		$this->members = array();
	}

	/**
	 * Validate the position against the map's geography, throwing on the first problem found.
	 *
	 * Deliberately *not* validated, because a Lab position is free: how many units a country has,
	 * whether that matches its supply center count, whether a country has any units at all, and
	 * whether the position could have arisen in a real game.
	 *
	 * The one-unit-per-province rule is a rule of the editor rather than a rule of Diplomacy. It
	 * holds for a position being built by hand, but a Retreats phase produced by the engine breaks
	 * it legitimately: a dislodged unit stays in its territory while the unit that dislodged it
	 * moves in, so both are there until the retreat is resolved. A position carrying engine state
	 * came out of the adjudicator and is true by construction, so that one check is not applied
	 * to it. Everything else - real map geography - is checked either way.
	 *
	 * @param bool|null $strict whether to apply the editor's one-unit-per-province rule; by
	 *                          default it is applied to hand-built positions only
	 * @throws Exception
	 */
	public function validate($strict = null)
	{
		if( is_null($strict) ) $strict = !$this->hasEngineState();

		$occupiedProvinces = array();
		$countryCount = $this->countryCount();

		foreach($this->units as $unit)
		{
			$terrID = (int)$unit['terrID'];
			$countryID = (int)$unit['countryID'];
			$type = $unit['type'];

			if( !isset($this->territories[$terrID]) )
				throw new Exception(l_t("Territory ID %s is not part of this variant's map.", $terrID));

			if( $countryID < 1 || $countryID > $countryCount )
				throw new Exception(l_t("Unit in %s has an invalid country.", $this->territories[$terrID]['name']));

			if( $type != 'Army' && $type != 'Fleet' )
				throw new Exception(l_t("Unit in %s has an invalid type; must be Army or Fleet.", $this->territories[$terrID]['name']));

			if( !$this->canHoldUnit($terrID, $type) )
				throw new Exception($type == 'Army'
					? l_t("%s cannot hold an army.", $this->territories[$terrID]['name'])
					: l_t("%s cannot hold a fleet.", $this->territories[$terrID]['name']));

			$provinceID = $this->provinceID($terrID);
			if( $strict && isset($occupiedProvinces[$provinceID]) )
				throw new Exception(l_t("%s has more than one unit in it; a province can hold at most one unit.", $this->territories[$provinceID]['name']));

			$occupiedProvinces[$provinceID] = true;
		}

		$ownedCenters = array();
		foreach($this->centers as $center)
		{
			$terrID = (int)$center['terrID'];
			$countryID = (int)$center['countryID'];

			if( !isset($this->territories[$terrID]) )
				throw new Exception(l_t("Territory ID %s is not part of this variant's map.", $terrID));

			if( !$this->isSupplyCenter($terrID) )
				throw new Exception(l_t("%s is not a supply center.", $this->territories[$terrID]['name']));

			// countryID 0 is allowed and means the supply center is explicitly neutral
			if( $countryID < 0 || $countryID > $countryCount )
				throw new Exception(l_t("Supply center %s has an invalid country.", $this->territories[$terrID]['name']));

			if( isset($ownedCenters[$terrID]) )
				throw new Exception(l_t("Supply center %s is assigned more than once.", $this->territories[$terrID]['name']));

			$ownedCenters[$terrID] = true;
		}

		if( !in_array($this->phase, array('Diplomacy','Retreats','Builds')) )
			throw new Exception(l_t("'%s' is not a phase a position can be placed in.", $this->phase));

		if( $this->turn < 0 )
			throw new Exception(l_t("The turn number cannot be negative."));
	}

	// --- Reading a position out of a game -----------------------------------------------------

	/**
	 * Read the current board state of a game into a LabPosition.
	 *
	 * Everything needed to put the game back exactly as it stands is taken, including the state
	 * that only matters part-way through a turn: which unit is dislodged, where the unit that
	 * dislodged it came from, which territories were stood off, and the orders as they were left.
	 * Restoring the result is then indistinguishable from never having left.
	 *
	 * @param Game $Game
	 * @return LabPosition
	 */
	public static function fromGame($Game)
	{
		global $DB;

		$Position = new LabPosition($Game->Variant);
		$Position->name = $Game->name;
		$Position->turn = (int)$Game->turn;
		$Position->phase = in_array($Game->phase, array('Diplomacy','Retreats','Builds')) ? $Game->phase : 'Diplomacy';

		// Ordered by ID, which is both stable and the order the units were created in. The engine
		// state below refers to units by their position in this list, and during a Retreats phase
		// two units share a territory, so nothing about a unit's country, type or territory could
		// serve as its identity.
		$unitIndexByID = array();

		$tabl = $DB->sql_tabl("SELECT id, countryID, type, terrID FROM wD_Units WHERE gameID = ".$Game->id." ORDER BY id");
		while( list($unitID, $countryID, $type, $terrID) = $DB->tabl_row($tabl) )
		{
			$unitIndexByID[(int)$unitID] = count($Position->units);
			$Position->units[] = array('countryID'=>(int)$countryID, 'type'=>$type, 'terrID'=>(int)$terrID);
		}

		$unitIndex = function($unitID) use ($unitIndexByID) {
			if( is_null($unitID) ) return null;
			return isset($unitIndexByID[(int)$unitID]) ? $unitIndexByID[(int)$unitID] : null;
		};

		$tabl = $DB->sql_tabl("SELECT ts.countryID, ts.terrID
			FROM wD_TerrStatus ts
			INNER JOIN wD_Territories t ON ( t.id = ts.terrID AND t.mapID = ".$Game->Variant->mapID." )
			WHERE ts.gameID = ".$Game->id." AND t.supply = 'Yes' AND ts.countryID <> 0
			ORDER BY ts.countryID, ts.terrID");
		while( list($countryID, $terrID) = $DB->tabl_row($tabl) )
			$Position->centers[] = array('countryID'=>(int)$countryID, 'terrID'=>(int)$terrID);

		$tabl = $DB->sql_tabl("SELECT terrID, countryID, standoff, occupiedFromTerrID, occupyingUnitID, retreatingUnitID
			FROM wD_TerrStatus WHERE gameID = ".$Game->id." ORDER BY terrID");
		while( $row = $DB->tabl_hash($tabl) )
			$Position->territoryStatus[] = array(
				'terrID' => (int)$row['terrID'],
				'countryID' => (int)$row['countryID'],
				'standoff' => ($row['standoff'] == 'Yes'),
				'occupiedFromTerrID' => is_null($row['occupiedFromTerrID']) ? null : (int)$row['occupiedFromTerrID'],
				'occupyingUnit' => $unitIndex($row['occupyingUnitID']),
				'retreatingUnit' => $unitIndex($row['retreatingUnitID'])
			);

		$tabl = $DB->sql_tabl("SELECT countryID, type, unitID, toTerrID, fromTerrID, viaConvoy
			FROM wD_Orders WHERE gameID = ".$Game->id." ORDER BY id");
		while( $row = $DB->tabl_hash($tabl) )
			$Position->orders[] = array(
				'countryID' => (int)$row['countryID'],
				'type' => $row['type'],
				'unit' => $unitIndex($row['unitID']),
				'toTerrID' => is_null($row['toTerrID']) ? null : (int)$row['toTerrID'],
				'fromTerrID' => is_null($row['fromTerrID']) ? null : (int)$row['fromTerrID'],
				'viaConvoy' => is_null($row['viaConvoy']) ? null : $row['viaConvoy']
			);

		$tabl = $DB->sql_tabl("SELECT countryID, status, orderStatus, supplyCenterNo, unitNo
			FROM wD_Members WHERE gameID = ".$Game->id." ORDER BY countryID");
		while( $row = $DB->tabl_hash($tabl) )
			$Position->members[] = array(
				'countryID' => (int)$row['countryID'],
				'status' => $row['status'],
				'orderStatus' => $row['orderStatus'],
				'supplyCenterNo' => (int)$row['supplyCenterNo'],
				'unitNo' => (int)$row['unitNo']
			);

		return $Position;
	}

	// --- JSON ---------------------------------------------------------------------------------

	/**
	 * The position as a plain array, ready to be JSON encoded. Territories and countries are
	 * written out by name so the format stays readable and portable, with IDs alongside so a
	 * program consuming it does not have to do name lookups.
	 *
	 * @return array
	 */
	public function toArray()
	{
		$units = array();
		foreach($this->units as $unit)
			$units[] = array(
				'country' => $this->countryNameByID($unit['countryID']),
				'countryID' => (int)$unit['countryID'],
				'type' => $unit['type'],
				'territory' => $this->territories[$unit['terrID']]['name'],
				'terrID' => (int)$unit['terrID']
			);

		$centers = array();
		foreach($this->centers as $center)
			$centers[] = array(
				'country' => $this->countryNameByID($center['countryID']),
				'countryID' => (int)$center['countryID'],
				'territory' => $this->territories[$center['terrID']]['name'],
				'terrID' => (int)$center['terrID']
			);

		$data = array(
			'format' => 'diplomacy-lab-position',
			'formatVersion' => 2,
			'name' => $this->name,
			'variant' => $this->Variant->name,
			'variantID' => (int)$this->Variant->id,
			'year' => $this->year(),
			'season' => $this->season(),
			'phase' => $this->phase,
			'turn' => (int)$this->turn,
			'units' => $units,
			'centers' => $centers
		);

		// The engine state is written out only when there is some, so a position built in the
		// editor stays as short and readable as it was in version 1 of this format.
		if( $this->hasEngineState() )
		{
			$territoryStatus = array();
			foreach($this->territoryStatus as $status)
				$territoryStatus[] = array(
					'territory' => $this->territories[$status['terrID']]['name'],
					'terrID' => (int)$status['terrID'],
					'countryID' => (int)$status['countryID'],
					'standoff' => (bool)$status['standoff'],
					'occupiedFromTerrID' => $status['occupiedFromTerrID'],
					'occupyingUnit' => $status['occupyingUnit'],
					'retreatingUnit' => $status['retreatingUnit']
				);

			$data['territoryStatus'] = $territoryStatus;
			$data['orders'] = $this->orders;
			$data['members'] = $this->members;
		}

		return $data;
	}

	/**
	 * The position as pretty-printed, human readable JSON.
	 * @return string
	 */
	public function toJSON()
	{
		return json_encode($this->toArray(), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Build a position from a decoded JSON structure.
	 *
	 * Territories and countries may be given either by name ('territory'/'country') or by ID
	 * ('terrID'/'countryID'). The year/season pair and a raw 'turn' are both accepted; 'turn' wins
	 * if both are given. Supply centers are optional, and any supply center not listed is neutral.
	 *
	 * @param array $data
	 * @param int $defaultVariantID used when the data does not name a variant
	 * @return LabPosition
	 * @throws Exception
	 */
	public static function fromArray($data, $defaultVariantID = 1)
	{
		if( !is_array($data) )
			throw new Exception(l_t("The position could not be read; expected a JSON object."));

		require_once(l_r('lib/variant.php'));

		$variantID = $defaultVariantID;
		if( isset($data['variantID']) && (int)$data['variantID'] > 0 )
			$variantID = (int)$data['variantID'];
		elseif( isset($data['variant']) && isset(array_flip(Config::$variants)[$data['variant']]) )
			$variantID = array_flip(Config::$variants)[$data['variant']];

		if( !isset(Config::$variants[$variantID]) )
			throw new Exception(l_t("Variant ID %s does not exist on this installation.", $variantID));

		$Variant = libVariant::loadFromVariantID($variantID);
		$Position = new LabPosition($Variant);

		if( isset($data['name']) ) $Position->name = (string)$data['name'];

		if( isset($data['turn']) )
			$Position->turn = (int)$data['turn'];
		else
			$Position->turn = self::turnFromYearSeason(
				isset($data['year']) ? $data['year'] : 1901,
				isset($data['season']) ? $data['season'] : 'Spring'
			);

		$Position->phase = self::normalizePhase(isset($data['phase']) ? $data['phase'] : 'Diplomacy');

		if( isset($data['units']) && is_array($data['units']) )
		{
			foreach($data['units'] as $unit)
			{
				$unit = (array)$unit;

				$terrID = $Position->resolveTerritory($unit);
				$countryID = $Position->resolveCountry($unit);

				$type = isset($unit['type']) ? ucfirst(strtolower(trim($unit['type']))) : '';
				if( $type == 'A' ) $type = 'Army';
				if( $type == 'F' ) $type = 'Fleet';

				$Position->units[] = array('countryID'=>$countryID, 'type'=>$type, 'terrID'=>$terrID);
			}
		}

		if( isset($data['centers']) && is_array($data['centers']) )
		{
			foreach($data['centers'] as $center)
			{
				$center = (array)$center;

				$terrID = $Position->resolveTerritory($center);
				$countryID = $Position->resolveCountry($center);

				// Explicitly neutral supply centers are simply left out
				if( $countryID > 0 )
					$Position->centers[] = array('countryID'=>$countryID, 'terrID'=>$terrID);
			}
		}

		$Position->readEngineState($data);

		$Position->validate();

		return $Position;
	}

	/**
	 * Read back the engine state written by toArray(), if the data carries any.
	 *
	 * Anything missing simply leaves the position without engine state, which is what a version 1
	 * file, or a position built in the editor, is: units, supply centers and a date.
	 *
	 * @param array $data
	 */
	private function readEngineState($data)
	{
		$unitCount = count($this->units);

		$unitRef = function($value) use ($unitCount) {
			if( !isset($value) || is_null($value) ) return null;
			$index = (int)$value;
			return ( $index >= 0 && $index < $unitCount ) ? $index : null;
		};

		if( isset($data['territoryStatus']) && is_array($data['territoryStatus']) )
		{
			foreach($data['territoryStatus'] as $status)
			{
				$status = (array)$status;

				$terrID = $this->resolveTerritory($status);

				$this->territoryStatus[] = array(
					'terrID' => $terrID,
					'countryID' => isset($status['countryID']) ? (int)$status['countryID'] : 0,
					'standoff' => !empty($status['standoff']),
					'occupiedFromTerrID' => ( isset($status['occupiedFromTerrID']) && !is_null($status['occupiedFromTerrID']) )
						? (int)$status['occupiedFromTerrID'] : null,
					'occupyingUnit' => $unitRef(isset($status['occupyingUnit']) ? $status['occupyingUnit'] : null),
					'retreatingUnit' => $unitRef(isset($status['retreatingUnit']) ? $status['retreatingUnit'] : null)
				);
			}
		}

		if( isset($data['orders']) && is_array($data['orders']) )
		{
			foreach($data['orders'] as $order)
			{
				$order = (array)$order;

				$this->orders[] = array(
					'countryID' => isset($order['countryID']) ? (int)$order['countryID'] : 0,
					'type' => isset($order['type']) ? (string)$order['type'] : '',
					'unit' => $unitRef(isset($order['unit']) ? $order['unit'] : null),
					'toTerrID' => ( isset($order['toTerrID']) && !is_null($order['toTerrID']) ) ? (int)$order['toTerrID'] : null,
					'fromTerrID' => ( isset($order['fromTerrID']) && !is_null($order['fromTerrID']) ) ? (int)$order['fromTerrID'] : null,
					'viaConvoy' => ( isset($order['viaConvoy']) && !is_null($order['viaConvoy']) ) ? (string)$order['viaConvoy'] : null
				);
			}
		}

		if( isset($data['members']) && is_array($data['members']) )
		{
			foreach($data['members'] as $member)
			{
				$member = (array)$member;

				$this->members[] = array(
					'countryID' => isset($member['countryID']) ? (int)$member['countryID'] : 0,
					'status' => isset($member['status']) ? (string)$member['status'] : 'Playing',
					'orderStatus' => isset($member['orderStatus']) ? (string)$member['orderStatus'] : '',
					'supplyCenterNo' => isset($member['supplyCenterNo']) ? (int)$member['supplyCenterNo'] : 0,
					'unitNo' => isset($member['unitNo']) ? (int)$member['unitNo'] : 0
				);
			}
		}
	}

	/**
	 * Build a position from a JSON string.
	 *
	 * @param string $json
	 * @param int $defaultVariantID
	 * @return LabPosition
	 * @throws Exception
	 */
	public static function fromJSON($json, $defaultVariantID = 1)
	{
		$data = json_decode($json, true);

		if( is_null($data) )
			throw new Exception(l_t("The position is not valid JSON: %s", json_last_error_msg()));

		return self::fromArray($data, $defaultVariantID);
	}

	/**
	 * Resolve the territory named or identified by an entry of the JSON 'units'/'centers' lists.
	 *
	 * @param array $entry
	 * @return int
	 * @throws Exception
	 */
	private function resolveTerritory($entry)
	{
		if( isset($entry['terrID']) && (int)$entry['terrID'] > 0 )
		{
			$terrID = (int)$entry['terrID'];
			if( !isset($this->territories[$terrID]) )
				throw new Exception(l_t("Territory ID %s is not part of this variant's map.", $terrID));

			return $terrID;
		}

		if( !isset($entry['territory']) )
			throw new Exception(l_t("A position entry is missing its 'territory'."));

		$terrID = $this->terrIDByName($entry['territory']);
		if( $terrID === false )
			throw new Exception(l_t("'%s' is not a territory on this variant's map.", $entry['territory']));

		return $terrID;
	}

	/**
	 * Resolve the country named or identified by an entry of the JSON 'units'/'centers' lists.
	 *
	 * @param array $entry
	 * @return int 0 for neutral
	 * @throws Exception
	 */
	private function resolveCountry($entry)
	{
		if( isset($entry['countryID']) )
			return (int)$entry['countryID'];

		if( !isset($entry['country']) )
			throw new Exception(l_t("A position entry is missing its 'country'."));

		if( strtolower(trim($entry['country'])) == 'neutral' )
			return 0;

		$countryID = $this->countryIDByName($entry['country']);
		if( $countryID === false )
			throw new Exception(l_t("'%s' is not a country in this variant.", $entry['country']));

		return $countryID;
	}

	// --- Writing a position into a game -------------------------------------------------------

	/**
	 * Replace a game's entire board state with this position.
	 *
	 * There are two kinds of position, and they are restored differently.
	 *
	 * A position built in the editor describes only where the units and supply centers are, so the
	 * game is set up as though it had just entered that phase: occupations are re-linked using
	 * webDiplomacy's own pre-game adjudicator, unit and center counts are recounted, and a fresh
	 * set of orders is generated by webDiplomacy's own order generator.
	 *
	 * A position read out of a live game also carries the engine's own state, and is put back
	 * exactly as it was: the territory records with their dislodgements and standoffs, the orders,
	 * and each power's counts. Nothing is recomputed, because recomputing is what loses a Retreats
	 * phase. Restoring such a position is meant to be indistinguishable from never having left it.
	 *
	 * In neither case is any rule of Diplomacy applied here: this writes state, and the adjudicator
	 * decides what may happen to it.
	 *
	 * @param processGame $Game a Lab/sandbox game; must not be a real multiplayer game
	 * @throws Exception
	 */
	public function applyToGame($Game)
	{
		global $DB;

		if( is_null($Game->sandboxCreatedByUserID) )
			throw new Exception("A Lab position can only be applied to a sandbox/Lab game.");

		$this->validate();

		// The pre-game adjudicator and the order generators read the game from $GLOBALS['Game'],
		// so make sure that is the game being written to.
		$GLOBALS['Game'] = $Game;

		require_once(l_r('gamemaster/orders/order.php'));
		require_once(l_r('gamemaster/orders/diplomacy.php'));
		require_once(l_r('gamemaster/orders/retreats.php'));
		require_once(l_r('gamemaster/orders/builds.php'));
		require_once(l_r('gamemaster/adjudicator/pregame.php'));

		// Clear the current board state. The archives are left alone; they are the game's history.
		$DB->sql_put("DELETE FROM wD_Orders WHERE gameID = ".$Game->id);
		$DB->sql_put("DELETE FROM wD_TerrStatus WHERE gameID = ".$Game->id);
		$DB->sql_put("DELETE FROM wD_Units WHERE gameID = ".$Game->id);

		// Place the units one at a time so that each one's new ID is known. The engine state refers
		// to units by their position in the list, so restoring it needs that mapping.
		$unitIDs = array();
		foreach($this->units as $index => $unit)
		{
			$DB->sql_put("INSERT INTO wD_Units ( gameID, countryID, terrID, type )
				VALUES ( ".$Game->id.", ".(int)$unit['countryID'].", ".(int)$unit['terrID'].", '".$unit['type']."' )");

			$unitIDs[$index] = (int)$DB->last_inserted();
		}

		// Set the turn and phase before anything reads them
		$DB->sql_put("UPDATE wD_Games SET
			turn = ".(int)$this->turn.",
			phase = '".$this->phase."',
			gameOver = 'No',
			processStatus = 'Not-processing',
			pauseTimeRemaining = NULL,
			/* A Lab board must never be adjudicated by the gamemaster on a timer; the only thing
			   that resolves it is the user pressing RESOLVE. The gamemaster only picks up games
			   whose processTime has passed, so the clock is pushed far out of reach. */
			processTime = ".(time() + self::CLOCK_FREEZE_SECONDS)."
			WHERE id = ".$Game->id);
		$Game->turn = (int)$this->turn;
		$Game->phase = $this->phase;

		if( $this->hasEngineState() )
			$this->restoreEngineState($Game, $unitIDs);
		else
			$this->setUpBuiltPosition($Game);

		// Archive the territory status for this turn so the map renders with the right ownership.
		$Game->archiveTerrStatus();
		$this->seedPreviousTurnArchive($Game);

		$DB->sql_put("COMMIT");
	}

	/**
	 * Give the turn before this one an ownership archive, if it never happened.
	 *
	 * The board reads its own history a turn at a time, and the supply centers it shows a Movement
	 * phase with are the ones archived for the turn *before* it (api/responses/game_state.php).
	 * webDiplomacy can rely on that turn having been played; a Lab position cannot, because it may
	 * start in any year, and then the turn before it never existed. Without something there the
	 * board cannot render the phase at all.
	 *
	 * So the position's own ownership stands in for it - the same thing webDiplomacy does for the
	 * turn before the first, where it uses the variant's starting ownership. A turn that really was
	 * played is left alone: this only fills a gap, it never overwrites history.
	 *
	 * @param processGame $Game
	 */
	private function seedPreviousTurnArchive($Game)
	{
		global $DB;

		$previousTurn = (int)$this->turn - 1;

		if( $previousTurn < 0 ) return;

		list($alreadyArchived) = $DB->sql_row("SELECT COUNT(*) FROM wD_TerrStatusArchive
			WHERE gameID = ".$Game->id." AND turn = ".$previousTurn);

		if( $alreadyArchived ) return;

		$DB->sql_put("INSERT INTO wD_TerrStatusArchive ( gameID, turn, terrID, countryID, standoff )
			SELECT gameID, ".$previousTurn.", terrID, countryID, standoff
			FROM wD_TerrStatus WHERE gameID = ".$Game->id);
	}

	/**
	 * Set up a game around a position that was built by hand, letting webDiplomacy work out
	 * everything the position does not say.
	 *
	 * @param processGame $Game
	 */
	private function setUpBuiltPosition($Game)
	{
		global $DB;

		// Assign supply center ownership
		if( count($this->centers) )
		{
			$scInserts = array();
			foreach($this->centers as $center)
				$scInserts[] = "(".$Game->id.", ".(int)$center['countryID'].", ".(int)$center['terrID'].")";

			$DB->sql_put("INSERT INTO wD_TerrStatus ( gameID, countryID, terrID ) VALUES ".implode(', ', $scInserts));
		}

		// Let webDiplomacy link TerrStatus to the units it placed, creating any TerrStatus records
		// that units in unowned territories need.
		$adj = $Game->Variant->adjudicatorPreGame();
		$adj->reassignUnitOccupations();

		// reassignUnitOccupations() gives any newly created TerrStatus record the occupying unit's
		// country. That is right for a game growing out of its starting position, but in the Lab
		// the user decides who owns each supply center, so force ownership back to exactly what
		// this position asked for. Supply centers left out of the position stay neutral.
		$ownedTerrIDs = array();
		foreach($this->centers as $center) $ownedTerrIDs[] = (int)$center['terrID'];

		$DB->sql_put("UPDATE wD_TerrStatus SET countryID = 0
			WHERE gameID = ".$Game->id.
			( count($ownedTerrIDs) ? " AND terrID NOT IN (".implode(',', $ownedTerrIDs).")" : "" ));

		foreach($this->centers as $center)
			$DB->sql_put("UPDATE wD_TerrStatus SET countryID = ".(int)$center['countryID']."
				WHERE gameID = ".$Game->id." AND terrID = ".(int)$center['terrID']);

		// Every country is playable again, whether or not it currently has units, and nobody is
		// ready to process the new position.
		$DB->sql_put("UPDATE wD_Members SET status = 'Playing', orderStatus = '', votes = '', pointsWon = NULL
			WHERE gameID = ".$Game->id);

		// Reload the members so the in-memory game agrees with the database, then let webDiplomacy
		// recount units/centers and generate this phase's orders.
		$Game->loadMembers();
		$Game->Members->countUnitsSCs();
		$Game->generateOrders();
	}

	/**
	 * Put back the engine state exactly as it was read out of a game.
	 *
	 * Nothing here is derived. The territory records are written as they stood, including which
	 * unit is dislodged, where the unit that dislodged it came from and which territories were
	 * stood off - the three things a Retreats phase is made of, and the three things that
	 * recomputing would throw away. The orders are written back as they were left, so the order
	 * generator is deliberately not run: it would wipe them and hand out empty ones.
	 *
	 * @param processGame $Game
	 * @param array $unitIDs the new wD_Units.id for each unit, keyed by its index in $units
	 */
	private function restoreEngineState($Game, $unitIDs)
	{
		global $DB;

		$unitID = function($index) use ($unitIDs) {
			return ( !is_null($index) && isset($unitIDs[$index]) ) ? $unitIDs[$index] : 'NULL';
		};

		$nullable = function($value) {
			return is_null($value) ? 'NULL' : (int)$value;
		};

		foreach($this->territoryStatus as $status)
			$DB->sql_put("INSERT INTO wD_TerrStatus
				( gameID, terrID, countryID, standoff, occupiedFromTerrID, occupyingUnitID, retreatingUnitID )
				VALUES (
					".$Game->id.",
					".(int)$status['terrID'].",
					".(int)$status['countryID'].",
					'".($status['standoff'] ? 'Yes' : 'No')."',
					".$nullable($status['occupiedFromTerrID']).",
					".$unitID($status['occupyingUnit']).",
					".$unitID($status['retreatingUnit'])."
				)");

		foreach($this->orders as $order)
			$DB->sql_put("INSERT INTO wD_Orders
				( gameID, countryID, type, unitID, toTerrID, fromTerrID, viaConvoy )
				VALUES (
					".$Game->id.",
					".(int)$order['countryID'].",
					'".$DB->escape($order['type'])."',
					".$unitID($order['unit']).",
					".$nullable($order['toTerrID']).",
					".$nullable($order['fromTerrID']).",
					".( is_null($order['viaConvoy']) ? 'NULL' : "'".$DB->escape($order['viaConvoy'])."'" )."
				)");

		// The counts a Builds phase is worked out from are restored rather than recounted, so that
		// the position is put back as the engine left it rather than as it would be recalculated.
		foreach($this->members as $member)
			$DB->sql_put("UPDATE wD_Members SET
					status = '".$DB->escape($member['status'])."',
					orderStatus = '".$DB->escape($member['orderStatus'])."',
					supplyCenterNo = ".(int)$member['supplyCenterNo'].",
					unitNo = ".(int)$member['unitNo'].",
					votes = '',
					pointsWon = NULL
				WHERE gameID = ".$Game->id." AND countryID = ".(int)$member['countryID']);

		$Game->loadMembers();
	}
}
