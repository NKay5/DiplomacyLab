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
 * Running a .sql file through mysqli.
 *
 * Kept apart from docker/lab-init.php so that the splitter can be exercised on its own, against a
 * real server, without running a deployment. install/checkSqlCompatibility.php does exactly that.
 *
 * @package Base
 * @subpackage Lab
 */

if( !function_exists('labLog') )
{
	function labLog($message) { fwrite(STDERR, '[lab-init] '.$message."\n"); }
}

/**
 * Split a .sql file into individual statements.
 *
 * mysqli_multi_query() cannot be used on webDiplomacy's install script: it stops part way through
 * the dump and still reports success, which silently leaves a half-built database behind. Rather
 * than depend on the mysql command line client being present in the image, the file is split here
 * and the statements are run one at a time, so every failure is seen.
 *
 * The split is quote-aware and skips comments. The dump contains no stored routines and no
 * DELIMITER directives, so a semicolon outside a quoted string always ends a statement.
 * mysqldump's conditional /*!...*​/ comments are deliberately left in place, because the server
 * is meant to execute them.
 *
 * @param string $sql
 * @return array
 */
function labSplitSqlStatements($sql)
{
	$statements = array();
	$current = '';
	$quote = null;
	$length = strlen($sql);

	for($i = 0; $i < $length; $i++)
	{
		$char = $sql[$i];

		if( $quote !== null )
		{
			$current .= $char;

			// Backslash escapes the next character inside ' and " strings
			if( $char === '\\' && $quote !== '`' && $i+1 < $length )
			{
				$current .= $sql[++$i];
				continue;
			}

			// A doubled quote inside a string is a literal quote, not the end of it
			if( $char === $quote )
			{
				if( $i+1 < $length && $sql[$i+1] === $quote ) $current .= $sql[++$i];
				else $quote = null;
			}

			continue;
		}

		if( $char === "'" || $char === '"' || $char === '`' )
		{
			$quote = $char;
			$current .= $char;
			continue;
		}

		// Line comments: # to end of line, and -- to end of line.
		//
		// MySQL only treats -- as a comment when whitespace follows, but the mysql command line
		// client also accepts it at the start of a line with no space, and webDiplomacy's install
		// script contains such lines ("--DROP TABLE ..."). Both forms are honoured here so that
		// the file behaves the same way it does through the client.
		$isLineComment = false;

		if( $char === '#' )
		{
			$isLineComment = true;
		}
		elseif( $char === '-' && substr($sql, $i, 2) === '--' )
		{
			$nextChar = ($i+2 < $length) ? $sql[$i+2] : "\n";

			if( $nextChar === ' ' || $nextChar === "\t" || $nextChar === "\n" || $nextChar === "\r" )
			{
				$isLineComment = true;
			}
			else
			{
				// Only when nothing but whitespace precedes it on this line
				$sinceNewline = substr($current, strrpos("\n".$current, "\n"));
				$isLineComment = ( trim($sinceNewline) === '' );
			}
		}

		if( $isLineComment )
		{
			$newline = strpos($sql, "\n", $i);
			$i = ($newline === false) ? $length : $newline;
			continue;
		}

		// Block comments, except the /*! ... */ form, which the server executes
		if( $char === '/' && substr($sql, $i, 2) === '/*' && substr($sql, $i, 3) !== '/*!' )
		{
			$end = strpos($sql, '*/', $i+2);
			$i = ($end === false) ? $length : $end + 1;
			continue;
		}

		if( $char === ';' )
		{
			if( trim($current) !== '' ) $statements[] = trim($current);
			$current = '';
			continue;
		}

		$current .= $char;
	}

	if( trim($current) !== '' ) $statements[] = trim($current);

	return $statements;
}

/**
 * Run every statement in a .sql file, stopping at the first failure.
 *
 * @param mysqli $link
 * @param string $sqlPath
 * @return bool
 */
function labRunSqlFile($link, $sqlPath)
{
	$sql = file_get_contents($sqlPath);
	if( $sql === false ) return false;

	$statements = labSplitSqlStatements($sql);

	foreach($statements as $index => $statement)
	{
		if( !mysqli_query($link, $statement) )
		{
			labLog('Statement '.($index+1).' of '.count($statements).' failed: '.mysqli_error($link));
			labLog('Statement began: '.substr(preg_replace('/\s+/', ' ', $statement), 0, 120));
			return false;
		}
	}

	labLog('Ran '.count($statements).' statements from '.basename($sqlPath).'.');

	return true;
}

/**
 * Empty the database again after a first install that did not finish.
 *
 * Only ever called when the database was verified empty moments earlier, so there is no user data
 * to lose. Without this, a first launch interrupted half way through would leave tables behind,
 * every later launch would see them and decide there was data to preserve, and the only way out
 * would be deleting the volume by hand.
 *
 * @param mysqli $link
 */
function labWipeFailedInstall($link)
{
	mysqli_query($link, 'SET FOREIGN_KEY_CHECKS = 0');

	$tables = array();
	$result = mysqli_query($link, 'SHOW TABLES');
	while( $result && ($row = mysqli_fetch_row($result)) ) $tables[] = $row[0];

	foreach($tables as $table)
		mysqli_query($link, 'DROP TABLE IF EXISTS `'.str_replace('`', '', $table).'`');

	mysqli_query($link, 'SET FOREIGN_KEY_CHECKS = 1');

	labLog('Removed the partial installation; the next start will begin again from empty.');
}
