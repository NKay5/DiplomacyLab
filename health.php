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
 * Diplomacy Lab health check.
 *
 * Reachable without signing in, because the hosting platform has to be able to tell whether the
 * container came up before anyone has authenticated. It therefore says as little as possible:
 * whether the application is configured and whether the database answers, and nothing else. It
 * reveals no version, no hostname, no configuration and no game data, and it never adjudicates.
 */

define('IN_CODE', 1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$configured = false;
$database = false;

if( is_readable(__DIR__.'/config.php') )
{
	require_once(__DIR__.'/config.php');
	$configured = ( class_exists('Config') && Config::$database_socket !== '' );

	if( $configured )
	{
		mysqli_report(MYSQLI_REPORT_OFF);

		$link = @mysqli_connect(
			Config::$database_socket,
			Config::$database_username,
			Config::$database_password,
			Config::$database_name
		);

		if( $link )
		{
			// A trivial read, to prove the connection is usable rather than merely open
			$result = @mysqli_query($link, 'SELECT 1');
			$database = ($result !== false);
			@mysqli_close($link);
		}
	}
}

$healthy = ( $configured && $database );

http_response_code($healthy ? 200 : 503);

print json_encode(array(
	'status' => $healthy ? 'ok' : 'unavailable',
	'configured' => $configured,
	'database' => $database
));
