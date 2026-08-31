<?php
/**
 * Ordinary webDiplomacy, untouched.
 *
 * Diplomacy Lab changes what the Ready button does, but only on a Lab board. This checks the
 * other side of that: an ordinary game still readies through webDiplomacy's own order interface,
 * one member at a time, and still waits for everyone before it processes.
 */
define('IN_CODE', true);
chdir('/var/www/html');
ob_start();
require_once('config.php'); require_once('header.php');
require_once(l_r('lib/variant.php'));
require_once(l_r('gamemaster/game.php'));
require_once(l_r('board/orders/orderinterface.php'));
ob_end_clean();

global $DB, $User, $Variant;

$fail = 0;
function check($what, $ok, $detail = '') {
	global $fail;
	print '  '.($ok ? 'ok  ' : 'FAIL').' '.$what.($ok || $detail === '' ? '' : "  -- $detail")."\n";
	if( !$ok ) $fail++;
}

list($uid) = $DB->sql_row("SELECT id FROM wD_Users WHERE username = 'owner'");
$User = new User((int)$uid); $GLOBALS['User'] = $User;
$Variant = libVariant::loadFromVariantName('Classic'); libVariant::setGlobals($Variant);

print "1. An ordinary (non-sandbox) game\n";

// Seven separate players, so this is nothing like a Lab board.
$playerIDs = array();
for($i = 1; $i <= 7; $i++)
{
	$name = 'nonlab'.$i;
	list($id) = $DB->sql_row("SELECT id FROM wD_Users WHERE username = '".$name."'");
	if( is_null($id) )
	{
		$DB->sql_put("INSERT INTO wD_Users ( username, email, password, points, type, timeJoined, timeLastSessionEnded )
			VALUES ( '".$name."', '".$name."@example.invalid', UNHEX(MD5(RAND())), 100, 'User', ".time().", ".time()." )");
		$id = $DB->last_inserted();
	}
	$playerIDs[$i] = (int)$id;
}
$DB->sql_put("COMMIT");

ob_start();
$Game = processGame::create(1, 'Non-Lab check '.substr(md5(uniqid('', true)), 0, 8), '', 0, 'Unranked',
	24*60*60, -1, 24*60*60, -1, 60, 'No', 'Regular', 'Wait', 'draw-votes-public', 0, 4, 'Members');
$GLOBALS['Game'] = $Game;
foreach($playerIDs as $countryID => $userID) processMember::create($userID, 0, $countryID);
$DB->sql_put("COMMIT");
$Game->process(); // Pre-game -> Diplomacy, with the standard opening position
$DB->sql_put("COMMIT");
ob_end_clean();

$Game = $Variant->processGame($Game->id);
$GLOBALS['Game'] = $Game;

list($sandbox) = $DB->sql_row("SELECT sandboxCreatedByUserID FROM wD_Games WHERE id = ".$Game->id);
check('the game is not a sandbox', is_null($sandbox));
check('it is in a Movement phase', $Game->phase === 'Diplomacy', $Game->phase);
list($units) = $DB->sql_row("SELECT COUNT(*) FROM wD_Units WHERE gameID = ".$Game->id);
check('it has the standard 22 units', (int)$units === 22, $units);

print "\n2. One member readies through webDiplomacy's own order interface\n";

$Game->loadMembers();
$Member = $Game->Members->ByCountryID[1];
$UserOne = new User($Member->userID);
$GLOBALS['User'] = $UserOne;

$OI = new OrderInterface($Game->id, $Game->variantID, $Member->userID, $Member->id,
	$Game->turn, $Game->phase, $Member->countryID, $Member->orderStatus, time() + 3600, false, false);
$OI->load(false);
$OI->validate();
$OI->readyToggle();
$OI->writeOrders();
$OI->writeOrderStatus();
$DB->sql_put("COMMIT");

$statuses = array();
$tabl = $DB->sql_tabl("SELECT countryID, orderStatus FROM wD_Members WHERE gameID = ".$Game->id." ORDER BY countryID");
while($r = $DB->tabl_hash($tabl)) $statuses[(int)$r['countryID']] = $r['orderStatus'];

check('the member who readied is Ready', strpos($statuses[1], 'Ready') !== false, $statuses[1]);
$others = 0;
for($i = 2; $i <= 7; $i++) if( strpos($statuses[$i], 'Ready') !== false ) $others++;
check('no other member was readied', $others === 0, implode(' ', $statuses));

$Game = $Variant->processGame($Game->id);
$GLOBALS['Game'] = $Game;
$Game->loadMembers();
check('the game is not ready to process yet', !$Game->Members->isReady());
check('and it has not moved on', $Game->phase === 'Diplomacy' && (int)$Game->turn === 0,
	$Game->phase.' turn '.$Game->turn);

print "\n3. Once every member is ready, it is ready to process\n";

$Game->loadMembers();
foreach($Game->Members->ByCountryID as $countryID => $M)
{
	if( $countryID == 1 ) continue;
	$U = new User($M->userID);
	$GLOBALS['User'] = $U;
	$O = new OrderInterface($Game->id, $Game->variantID, $M->userID, $M->id,
		$Game->turn, $Game->phase, $M->countryID, $M->orderStatus, time() + 3600, false, false);
	$O->load(false);
	$O->validate();
	$O->readyToggle();
	$O->writeOrders();
	$O->writeOrderStatus();
}
$DB->sql_put("COMMIT");

$Game = $Variant->processGame($Game->id);
$GLOBALS['Game'] = $Game;
$Game->loadMembers();
check('the game is now ready to process', $Game->Members->isReady());

ob_start(); $Game->process(); $DB->sql_put("COMMIT"); ob_end_clean();
$Game = $Variant->processGame($Game->id);
check('processing moved it to the next phase', (int)$Game->turn === 1 || $Game->phase !== 'Diplomacy',
	$Game->phase.' turn '.$Game->turn);

print "\n4. The Lab's tables are not involved\n";
list($labRows) = $DB->sql_row("SELECT COUNT(*) FROM wD_LabBranches WHERE gameID = ".$Game->id);
check('the game is not part of any scenario', (int)$labRows === 0, $labRows);

// Tidy up
$GLOBALS['User'] = $User;
ob_start(); processGame::eraseGame($Game->id, false); ob_end_clean();
$DB->sql_put("DELETE FROM wD_Users WHERE username LIKE 'nonlab%'");
$DB->sql_put("COMMIT");

print "\n".($fail ? "$fail CHECK(S) FAILED" : "all checks passed")."\n";
