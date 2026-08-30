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
 * Diplomacy Lab first-boot and every-boot setup.
 *
 * Run from the container entrypoint before Apache starts. It is idempotent: the first run builds
 * everything, and every run after that finds the work already done and leaves the existing data
 * completely alone.
 *
 *   1. Work out where the database is, from whichever variables the platform provides.
 *   2. Wait for it to accept connections.
 *   3. Remember, in the database, one random secret that signs this deployment's sessions, so
 *      redeploying does not invalidate them.
 *   4. Write config.php from the environment.
 *   5. Install the webDiplomacy schema, but only if the database is empty.
 *   6. Compile the map data the Lab needs.
 *   7. Create or update the single owner account.
 *
 * Nothing here prints a password, a connection string or any other secret.
 */

define('IN_CODE', 1);
define('RUNNINGFROMCLI', 1);

$appRoot = dirname(__DIR__);

function labLog($message) { fwrite(STDERR, '[lab-init] '.$message."\n"); }
function labFail($message) { labLog('ERROR: '.$message); exit(1); }

// --- 1. Where is the database? ---------------------------------------------------------------

/**
 * Read the database connection from the environment.
 *
 * Managed MySQL is exposed differently by different platforms, so all the usual shapes are
 * accepted: a single URL, Railway's MYSQL* variables, or explicit LAB_DB_* overrides.
 *
 * @return array
 */
function labDatabaseSettings()
{
	$pick = function(array $names, $default = '') {
		foreach($names as $name)
		{
			$value = getenv($name);
			if( $value !== false && $value !== '' ) return $value;
		}
		return $default;
	};

	$url = $pick(array('LAB_DB_URL', 'MYSQL_URL', 'MYSQL_PRIVATE_URL', 'DATABASE_URL'));

	$settings = array('host'=>'', 'port'=>3306, 'user'=>'', 'password'=>'', 'name'=>'');

	if( $url !== '' )
	{
		$parts = parse_url($url);
		if( $parts === false ) labFail('The database URL could not be understood.');

		$settings['host'] = isset($parts['host']) ? $parts['host'] : '';
		$settings['port'] = isset($parts['port']) ? (int)$parts['port'] : 3306;
		$settings['user'] = isset($parts['user']) ? rawurldecode($parts['user']) : '';
		$settings['password'] = isset($parts['pass']) ? rawurldecode($parts['pass']) : '';
		$settings['name'] = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
	}

	// Explicit variables win over anything taken from a URL
	$settings['host'] = $pick(array('LAB_DB_HOST', 'MYSQLHOST', 'MYSQL_HOST'), $settings['host']);
	$settings['port'] = (int)$pick(array('LAB_DB_PORT', 'MYSQLPORT', 'MYSQL_PORT'), (string)$settings['port']);
	$settings['user'] = $pick(array('LAB_DB_USER', 'MYSQLUSER', 'MYSQL_USER'), $settings['user']);
	$settings['password'] = $pick(array('LAB_DB_PASSWORD', 'MYSQLPASSWORD', 'MYSQL_PASSWORD', 'MYSQL_ROOT_PASSWORD'), $settings['password']);
	$settings['name'] = $pick(array('LAB_DB_NAME', 'MYSQLDATABASE', 'MYSQL_DATABASE'), $settings['name']);

	if( $settings['name'] === '' ) $settings['name'] = 'railway';
	if( $settings['port'] <= 0 ) $settings['port'] = 3306;

	if( $settings['host'] === '' )
		labFail('No database is attached. Add a MySQL database to this project and connect it to this service.');

	return $settings;
}

$db = labDatabaseSettings();
labLog('Database host resolved; connecting.');

// --- 2. Wait for the database ------------------------------------------------------------------

mysqli_report(MYSQLI_REPORT_OFF);

$link = null;
$deadline = time() + 180;

while( time() < $deadline )
{
	$link = @mysqli_connect($db['host'], $db['user'], $db['password'], '', $db['port']);
	if( $link ) break;

	sleep(2);
}

if( !$link ) labFail('The database did not become available in time.');

labLog('Database is up.');

if( !@mysqli_select_db($link, $db['name']) )
{
	// A freshly created database service may not have the database itself yet
	$escapedName = str_replace('`', '', $db['name']);
	@mysqli_query($link, "CREATE DATABASE IF NOT EXISTS `".$escapedName."` DEFAULT CHARACTER SET utf8");

	if( !@mysqli_select_db($link, $db['name']) )
		labFail('The database exists but could not be selected.');
}

mysqli_set_charset($link, 'utf8');

// --- 3. One stable secret for this deployment --------------------------------------------------

/*
 * webDiplomacy derives password hashes and signed tokens from Config::$salt and Config::$secret.
 * If those changed on every deploy, every session and every stored password would break, so one
 * random secret is generated on first boot and kept in the database from then on.
 */
mysqli_query($link, "CREATE TABLE IF NOT EXISTS `wD_LabConfig` (
	`name` varchar(64) NOT NULL,
	`value` text NOT NULL,
	PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8");

$labSecret = '';
$result = mysqli_query($link, "SELECT value FROM wD_LabConfig WHERE name = 'secret'");
if( $result && ($row = mysqli_fetch_row($result)) ) $labSecret = $row[0];

if( $labSecret === '' )
{
	$labSecret = bin2hex(random_bytes(32));
	$statement = mysqli_prepare($link, "INSERT INTO wD_LabConfig (name, value) VALUES ('secret', ?)");
	mysqli_stmt_bind_param($statement, 's', $labSecret);

	if( !mysqli_stmt_execute($statement) )
		labFail('The deployment secret could not be stored.');

	labLog('Generated this deployment\'s signing secret.');
}

$derive = function($purpose) use ($labSecret) { return hash('sha256', $labSecret.'|'.$purpose); };

// --- 4. Write config.php -----------------------------------------------------------------------

/*
 * The database credentials are read from the environment at request time rather than written out,
 * so they never sit in a file. The signing secrets have to be written, because they come from the
 * database and config.php is loaded before any connection exists; the file is kept private.
 */
$ownerUsername = getenv('LAB_ADMIN_USER');
if( $ownerUsername === false || trim($ownerUsername) === '' ) $ownerUsername = 'owner';
$ownerUsername = trim($ownerUsername);

$ownerPassword = getenv('LAB_ADMIN_PASSWORD');
if( $ownerPassword === false || $ownerPassword === '' )
	labFail('LAB_ADMIN_PASSWORD is not set. Diplomacy Lab will not start without a password, so that it can never be left open.');

$config = "<?php\n"
	."/* Generated by docker/lab-init.php on each boot. Not for editing, and not in source control. */\n"
	."defined('IN_CODE') or die('This script can not be run by itself.');\n\n"
	."require_once(__DIR__.'/config.sample.php');\n\n"
	."\$labEnv = function(array \$names, \$default = '') {\n"
	."	foreach(\$names as \$name) { \$v = getenv(\$name); if( \$v !== false && \$v !== '' ) return \$v; }\n"
	."	return \$default;\n"
	."};\n"
	."\$labUrl = \$labEnv(array('LAB_DB_URL','MYSQL_URL','MYSQL_PRIVATE_URL','DATABASE_URL'));\n"
	."\$labParts = \$labUrl === '' ? array() : (array)parse_url(\$labUrl);\n\n"
	."Config::\$database_socket = \$labEnv(array('LAB_DB_HOST','MYSQLHOST','MYSQL_HOST'), isset(\$labParts['host']) ? \$labParts['host'] : '');\n"
	."Config::\$database_username = \$labEnv(array('LAB_DB_USER','MYSQLUSER','MYSQL_USER'), isset(\$labParts['user']) ? rawurldecode(\$labParts['user']) : '');\n"
	."Config::\$database_password = \$labEnv(array('LAB_DB_PASSWORD','MYSQLPASSWORD','MYSQL_PASSWORD','MYSQL_ROOT_PASSWORD'), isset(\$labParts['pass']) ? rawurldecode(\$labParts['pass']) : '');\n"
	."Config::\$database_name = \$labEnv(array('LAB_DB_NAME','MYSQLDATABASE','MYSQL_DATABASE'), isset(\$labParts['path']) ? ltrim(\$labParts['path'],'/') : 'railway');\n\n"
	."/* A Lab resolves synchronously when the user presses RESOLVE; there is no Redis service. */\n"
	."Config::\$redisHost = \$labEnv(array('LAB_REDIS_HOST','REDIS_HOST'), '');\n"
	."Config::\$redisPort = (int)\$labEnv(array('LAB_REDIS_PORT','REDIS_PORT'), '6379');\n\n"
	."Config::\$salt = ".var_export($derive('salt'), true).";\n"
	."Config::\$secret = ".var_export($derive('secret'), true).";\n"
	."Config::\$gameMasterSecret = ".var_export($derive('gamemaster'), true).";\n"
	."Config::\$jsonSecret = ".var_export($derive('json'), true).";\n\n"
	."Config::\$debug = false;\n"
	."Config::\$labMode = true;\n"
	."Config::\$labOwnerUsername = ".var_export($ownerUsername, true).";\n";

$configPath = $appRoot.'/config.php';
if( file_put_contents($configPath, $config) === false )
	labFail('config.php could not be written.');

@chmod($configPath, 0640);
labLog('Wrote configuration.');

// --- 5. Install the schema, but only into an empty database ------------------------------------

/**
 * Run a .sql file through the MySQL client.
 *
 * The client is used rather than mysqli_multi_query() because webDiplomacy's install script is a
 * full mysqldump: multi_query stops part way through it and reports success, which leaves a
 * half-built database behind. The credentials go in a private defaults file so that they never
 * appear in the process list.
 *
 * @param array $db
 * @param string $sqlPath
 * @return bool
 */
function labRunSqlFile(array $db, $sqlPath)
{
	$client = null;
	foreach(array('mysql', 'mariadb') as $candidate)
	{
		$which = trim((string)@shell_exec('command -v '.escapeshellarg($candidate).' 2>/dev/null'));
		if( $which !== '' ) { $client = $which; break; }
	}

	if( $client === null ) labFail('No MySQL client is available in this image.');

	$defaults = tempnam(sys_get_temp_dir(), 'labdb');
	if( $defaults === false ) labFail('A temporary file could not be created.');

	chmod($defaults, 0600);
	file_put_contents($defaults,
		"[client]\n"
		."host=".$db['host']."\n"
		."port=".$db['port']."\n"
		."user=".$db['user']."\n"
		."password=\"".str_replace(array('\\','"'), array('\\\\','\\"'), $db['password'])."\"\n"
	);

	$command = escapeshellarg($client)
		.' --defaults-extra-file='.escapeshellarg($defaults)
		.' '.escapeshellarg($db['name'])
		.' < '.escapeshellarg($sqlPath).' 2>&1';

	exec($command, $output, $status);

	unlink($defaults);

	if( $status !== 0 )
		labLog('MySQL client reported: '.implode(' | ', array_slice($output, 0, 3)));

	return ($status === 0);
}

$result = mysqli_query($link, "SHOW TABLES LIKE 'wD_Users'");
$schemaPresent = ($result && mysqli_num_rows($result) > 0);

if( $schemaPresent )
{
	labLog('Existing data found; leaving it untouched.');
}
else
{
	labLog('Empty database; installing the schema.');

	$sqlPath = $appRoot.'/install/FullInstall/fullInstall.sql';
	if( !is_readable($sqlPath) ) labFail('The install script is missing from the image.');

	if( !labRunSqlFile($db, $sqlPath) )
		labFail('The schema could not be installed.');

	labLog('Schema installed.');
}

// The Lab's own tables ship in the full install, but a database created before Diplomacy Lab
// existed will not have them. They are created with IF NOT EXISTS, so this is safe to repeat.
$labSchemaPath = $appRoot.'/install/DiplomacyLab/lab.sql';
if( is_readable($labSchemaPath) && !labRunSqlFile($db, $labSchemaPath) )
	labFail('The Diplomacy Lab tables could not be created.');

/*
 * Verify the result rather than assuming it. webDiplomacy refuses to run when the schema version
 * does not match the code, so a partial install has to stop the deployment here, where the reason
 * is visible in the logs, rather than at the first request.
 */
require_once($appRoot.'/global/definitions.php');

$result = mysqli_query($link, "SELECT value FROM wD_Misc WHERE name = 'Version'");
$installedVersion = ($result && ($row = mysqli_fetch_row($result))) ? (int)$row[0] : 0;

if( $installedVersion !== (int)VERSION )
	labFail('The database schema is version '.$installedVersion.' but this code needs '.VERSION.'.');

labLog('Schema version '.$installedVersion.' confirmed.');

mysqli_close($link);

// --- 6 and 7. Compile the map data and create the owner ----------------------------------------

/*
 * From here the application's own code is used, so that the map is compiled and the account is
 * created exactly the way webDiplomacy does it.
 */
chdir($appRoot);

ob_start();
require_once($appRoot.'/config.php');
require_once($appRoot.'/header.php');
require_once($appRoot.'/lib/variant.php');
require_once($appRoot.'/lib/labMode.php');
ob_end_clean();

global $DB;

try
{
	$Variant = libVariant::loadFromVariantName('Classic');
	libVariant::setGlobals($Variant);

	list($territoryCount) = $DB->sql_row("SELECT COUNT(*) FROM wD_Territories WHERE mapID = ".$Variant->mapID);

	if( (int)$territoryCount < 1 )
		labFail('The Classic map compiled to no territories.');

	labLog('Classic map ready ('.$territoryCount.' territories).');
}
catch(Exception $e)
{
	labFail('The Classic map could not be prepared.');
}

try
{
	$ownerID = libLabMode::createOrUpdateOwner($ownerUsername, $ownerPassword);
	labLog('Owner account ready.');
}
catch(Exception $e)
{
	labFail('The owner account could not be created.');
}

$DB->sql_put("COMMIT");

labLog('Diplomacy Lab is ready.');
exit(0);
