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
 * Lab mode: running this webDiplomacy install as one person's private Diplomacy Lab.
 *
 * A Lab deployment is not a webDiplomacy server. There is exactly one account, nobody can
 * register, and only the pages Diplomacy Lab actually uses are reachable. This class is the single
 * place that enforces all of that, so the rest of webDiplomacy is left alone.
 *
 * Access control has two layers, and both must hold:
 *
 *  1. The web server requires HTTP Basic authentication for every request except the health check.
 *     Nothing reaches PHP without it, so no route, asset or API endpoint can be called anonymously.
 *  2. This class then checks that the authenticated name is the configured owner, and signs that
 *     owner in to their webDiplomacy account, so there is only ever one credential to remember.
 *
 * It fails closed: if Lab mode is on but no owner is configured, or the web server is not actually
 * asking for credentials, the site refuses to serve rather than running unprotected.
 *
 * @package Base
 * @subpackage Lab
 */
class libLabMode
{
	/**
	 * The scripts a Lab deployment needs. Anything else is redirected to the Lab.
	 *
	 * board.php, map.php, ajax.php and api.php are here because the Lab embeds webDiplomacy's own
	 * board for order entry, which draws the map, saves orders over ajax.php, and reaches the
	 * lab/* API entries through api.php.
	 *
	 * @var array
	 */
	private static $allowedScripts = array(
		'lab.php',
		'board.php',
		'map.php',
		'ajax.php',
		'api.php',
		'logon.php',
		'health.php',
		'cache.php',
		'message.php'
	);

	/**
	 * Whether this install is running as a private Diplomacy Lab.
	 *
	 * @return bool
	 */
	public static function isEnabled()
	{
		return ( isset(Config::$labMode) && Config::$labMode );
	}

	/**
	 * Whether this Lab is running privately on the user's own machine.
	 *
	 * A local Lab is published on 127.0.0.1 only (see docker-compose.local.yml), so it cannot be
	 * reached from anywhere else and there is no password to type. Everywhere else the Lab is on
	 * the open internet and the owner's credentials are required for every request.
	 *
	 * @return bool
	 */
	public static function isLocal()
	{
		return ( self::isEnabled() && isset(Config::$labLocalMode) && Config::$labLocalMode );
	}

	/**
	 * Whether this request arrived at a name that only resolves on the machine itself.
	 *
	 * The container cannot see the real client address, because Docker rewrites it when it
	 * publishes a port, so the loopback publish in docker-compose.local.yml is what actually keeps
	 * a local Lab private. This is a second line of defence: if a local-mode Lab is ever published
	 * more widely by mistake, requests arriving under any other name are still refused.
	 *
	 * @return bool
	 */
	private static function requestIsLoopback()
	{
		if( !isset($_SERVER['HTTP_HOST']) ) return false;

		$host = strtolower($_SERVER['HTTP_HOST']);

		// Drop the port, and the brackets IPv6 literals are written with
		if( ($colon = strrpos($host, ':')) !== false && strpos($host, ']') !== strlen($host)-1 )
		{
			$beforeColon = substr($host, 0, $colon);
			if( strpos($beforeColon, ':') === false || substr($beforeColon, -1) === ']' )
				$host = $beforeColon;
		}

		$host = trim($host, '[]');

		return in_array($host, array('127.0.0.1', 'localhost', '::1', '0:0:0:0:0:0:0:1'));
	}

	/**
	 * The owner's username, as configured for this deployment.
	 *
	 * @return string
	 */
	public static function ownerUsername()
	{
		return ( isset(Config::$labOwnerUsername) ? trim((string)Config::$labOwnerUsername) : '' );
	}

	/**
	 * The name the web server authenticated for this request, or '' if it did not authenticate one.
	 *
	 * @return string
	 */
	public static function authenticatedUsername()
	{
		if( isset($_SERVER['PHP_AUTH_USER']) && $_SERVER['PHP_AUTH_USER'] !== '' )
			return (string)$_SERVER['PHP_AUTH_USER'];

		// Some server setups pass the credentials through without splitting them out
		if( isset($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'], 'basic ') === 0 )
		{
			$decoded = base64_decode(substr($_SERVER['HTTP_AUTHORIZATION'], 6));
			if( $decoded !== false && strpos($decoded, ':') !== false )
				return substr($decoded, 0, strpos($decoded, ':'));
		}

		return '';
	}

	/**
	 * Stop the request with a bare status code and no detail.
	 *
	 * Deliberately does not use libHTML, so that a misconfiguration cannot end up rendering a
	 * webDiplomacy page, and says nothing about why beyond the status itself.
	 *
	 * @param int $status
	 * @param string $message a short, non-revealing message
	 */
	private static function refuse($status, $message)
	{
		if( !headers_sent() )
		{
			http_response_code($status);
			header('Content-Type: text/plain; charset=utf-8');
			header('Cache-Control: no-store');
		}

		die($message."\n");
	}

	/**
	 * Enforce the access rules for this request. Called once, from header.php.
	 *
	 * Runs for every request that goes through header.php, including api.php and ajax.php, so no
	 * Lab endpoint can be reached without the owner's credentials.
	 */
	public static function enforce()
	{
		if( !self::isEnabled() ) return;

		// Command line tooling (the deployment's own setup scripts) is not web-facing
		if( php_sapi_name() === 'cli' || defined('RUNNINGFROMCLI') ) return;

		$owner = self::ownerUsername();

		// Fail closed: a Lab with no owner configured must not serve anything
		if( $owner === '' )
			self::refuse(503, 'Diplomacy Lab is not configured.');

		if( self::isLocal() )
		{
			// Private to this machine: the loopback publish is the boundary, so there is no
			// password. Requests arriving under any other name are refused all the same.
			if( !self::requestIsLoopback() )
				self::refuse(403, 'Forbidden.');
		}
		else
		{
			$authenticated = self::authenticatedUsername();

			// Fail closed: if the web server is not asking for credentials, something is
			// misconfigured and the Lab would otherwise be open to anyone who found the URL.
			if( $authenticated === '' )
				self::refuse(503, 'Diplomacy Lab is not configured.');

			if( !hash_equals($owner, $authenticated) )
				self::refuse(403, 'Forbidden.');
		}

		// From here the request is the owner's. AJAX requests do not load $User and are authorized
		// by their own signed order tokens, so they stop here.
		if( defined('AJAX') ) return;

		self::signInOwner();
		self::restrictToLabPages();
	}

	/**
	 * Sign the owner in to their webDiplomacy account if they are not already.
	 *
	 * The web server has already authenticated them by this point, so this only turns that into the
	 * webDiplomacy session the board and the Lab expect. It means one set of credentials rather
	 * than two.
	 */
	private static function signInOwner()
	{
		global $User, $DB;

		if( isset($User) && $User->type['User'] ) return;

		$owner = self::ownerUsername();

		list($userID) = $DB->sql_row("SELECT id FROM wD_Users WHERE username = '".$DB->escape($owner)."'");

		if( !$userID )
			self::refuse(503, 'Diplomacy Lab is not configured.');

		libAuth::keySet($userID, true);

		$User = new User((int)$userID);
		$GLOBALS['User'] = $User;
	}

	/**
	 * Send anything that is not part of Diplomacy Lab back to the Lab.
	 *
	 * This is what keeps the rest of webDiplomacy - the forum, game creation, profiles, the
	 * matchmaking pages - out of reach, without having to remove any of it.
	 */
	private static function restrictToLabPages()
	{
		$script = basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '');

		if( in_array($script, self::$allowedScripts) ) return;

		// The Lab is the board, so the root goes straight to it rather than to a list of
		// positions. lab.php is still there to start a new scenario and to export one.
		if( $script === 'index.php' )
		{
			require_once(l_r('lib/lab.php'));
			require_once(l_r('lib/labTree.php'));

			if( !headers_sent() )
			{
				http_response_code(302);
				header('Location: '.libLab::boardURL(libLabTree::currentOrNewGameID()));
			}

			die();
		}

		if( !headers_sent() )
		{
			http_response_code(302);
			header('Location: lab.php');
		}

		die();
	}

	/**
	 * Whether new accounts can be created. Always false in Lab mode: there is one account, and it
	 * is created by the deployment itself.
	 *
	 * @return bool
	 */
	public static function registrationAllowed()
	{
		return !self::isEnabled();
	}

	/**
	 * Create or update the owner's webDiplomacy account.
	 *
	 * Run by the deployment's setup script, not by web requests. The account is an ordinary user,
	 * deliberately not an admin, because webDiplomacy turns debugging output on for admins and a
	 * production deployment must not show stack traces.
	 *
	 * @param string $username
	 * @param string $password
	 * @return int the owner's user ID
	 */
	public static function createOrUpdateOwner($username, $password)
	{
		global $DB;

		$username = trim($username);
		if( $username === '' ) throw new Exception('No owner username given.');
		if( (string)$password === '' ) throw new Exception('No owner password given.');

		$passwordHash = libAuth::pass_Hash($password);
		$escapedName = $DB->escape($username);

		list($userID) = $DB->sql_row("SELECT id FROM wD_Users WHERE username = '".$escapedName."'");

		if( $userID )
		{
			$DB->sql_put("UPDATE wD_Users
				SET password = UNHEX('".$passwordHash."'),
					type = 'User'
				WHERE id = ".(int)$userID);
		}
		else
		{
			$DB->sql_put("INSERT INTO wD_Users
				( username, password, email, type, comment, homepage, hideEmail, timeJoined, locale,
				  timeLastSessionEnded, points, notifications )
				VALUES (
					'".$escapedName."',
					UNHEX('".$passwordHash."'),
					'".$escapedName."@diplomacy.lab',
					'User', '', '', 'Yes', ".time().", 'English', ".time().", 100, ''
				)");

			$userID = $DB->last_inserted();
		}

		$DB->sql_put("COMMIT");

		return (int)$userID;
	}
}
