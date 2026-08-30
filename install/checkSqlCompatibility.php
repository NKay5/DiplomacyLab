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
 * Check that the install scripts run on a given database server.
 *
 * webDiplomacy is normally developed against MariaDB, but a hosted deployment may well be given
 * MySQL instead, and the two differ in enough small ways that "it installs here" is not the same
 * as "it installs there". This runs every statement of the install scripts against whichever
 * server it is pointed at and reports *all* the failures rather than stopping at the first, so a
 * single run gives the whole list.
 *
 * It is a developer tool: it is not shipped in the container image and is never run by a
 * deployment.
 *
 * Usage:
 *   php install/checkSqlCompatibility.php --host=127.0.0.1 --port=3306 \
 *       --user=root --password=secret --database=compat_check
 *
 * The database is dropped and recreated, so point it at a throwaway one.
 */

define('IN_CODE', 1);
define('RUNNINGFROMCLI', 1);

require_once(__DIR__.'/../docker/labSqlFile.php');

$options = getopt('', array('host::', 'port::', 'user::', 'password::', 'database::'));

$host = isset($options['host']) ? $options['host'] : '127.0.0.1';
$port = isset($options['port']) ? (int)$options['port'] : 3306;
$user = isset($options['user']) ? $options['user'] : 'root';
$password = isset($options['password']) ? $options['password'] : '';
$database = isset($options['database']) ? $options['database'] : 'compat_check';

mysqli_report(MYSQLI_REPORT_OFF);

$link = @mysqli_connect($host, $user, $password, '', $port);
if( !$link )
{
	fwrite(STDERR, "Could not connect: ".mysqli_connect_error()."\n");
	exit(2);
}

list($serverVersion) = mysqli_fetch_row(mysqli_query($link, 'SELECT VERSION()'));
print "Server: ".$serverVersion."\n";

$escapedName = str_replace('`', '', $database);
mysqli_query($link, "DROP DATABASE IF EXISTS `".$escapedName."`");
mysqli_query($link, "CREATE DATABASE `".$escapedName."` DEFAULT CHARACTER SET utf8mb4");
mysqli_select_db($link, $database);

$failures = 0;
$total = 0;

foreach(array(__DIR__.'/FullInstall/fullInstall.sql', __DIR__.'/DiplomacyLab/lab.sql') as $sqlPath)
{
	if( !is_readable($sqlPath) )
	{
		fwrite(STDERR, "Missing: ".$sqlPath."\n");
		exit(2);
	}

	$statements = labSplitSqlStatements(file_get_contents($sqlPath));
	print "\n".basename($sqlPath).": ".count($statements)." statements\n";

	foreach($statements as $index => $statement)
	{
		$total++;

		if( mysqli_query($link, $statement) ) continue;

		$failures++;

		// Keep going rather than stopping, so one run finds every incompatibility
		print "\n  FAILED statement ".($index+1)." of ".count($statements)."\n";
		print "    ".mysqli_error($link)."\n";
		print "    ".substr(preg_replace('/\s+/', ' ', $statement), 0, 220)."\n";
	}
}

// Warnings are not failures, but a server that merely tolerates something today may reject it
// later, so they are worth seeing.
$result = mysqli_query($link, 'SHOW WARNINGS');
$warnings = array();
while( $result && ($row = mysqli_fetch_assoc($result)) ) $warnings[] = $row['Message'];

$result = mysqli_query($link, "SELECT value FROM wD_Misc WHERE name = 'Version'");
$version = ($result && ($row = mysqli_fetch_row($result))) ? $row[0] : '(not readable)';

$result = mysqli_query($link, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '".mysqli_real_escape_string($link, $database)."'");
list($tableCount) = mysqli_fetch_row($result);

print "\n".str_repeat('-', 70)."\n";
print "Statements run : ".$total."\n";
print "Failures       : ".$failures."\n";
print "Tables created : ".$tableCount."\n";
print "Schema version : ".$version."\n";

if( count($warnings) ) print "Last warnings  : ".implode(' | ', array_slice($warnings, 0, 3))."\n";

print $failures ? "\nRESULT: INCOMPATIBLE\n" : "\nRESULT: OK\n";

exit($failures ? 1 : 0);
