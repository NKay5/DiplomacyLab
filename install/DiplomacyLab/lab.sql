-- Diplomacy Lab tables.
--
-- Diplomacy Lab is a free tactical analysis board built on webDiplomacy's sandbox games. It adds
-- no columns to any existing table; these two tables are all it stores.
--
-- These statements are also appended to install/FullInstall/fullInstall.sql, and lib/lab.php
-- creates them on demand, so an existing database does not have to be migrated by hand. Running
-- this file against an older database is harmless.

-- One row per Lab board. snapshotJSON holds the position as it stood immediately before the last
-- RESOLVE, which is what the RESET button restores.
CREATE TABLE IF NOT EXISTS `wD_LabGames` (
  `gameID` mediumint(8) unsigned NOT NULL,
  `userID` mediumint(8) unsigned NOT NULL,
  `name` varchar(120) NOT NULL DEFAULT '',
  `snapshotJSON` mediumtext DEFAULT NULL,
  `timeCreated` int(10) unsigned NOT NULL DEFAULT 0,
  `timeLastUsed` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`gameID`),
  KEY `userID` (`userID`,`timeLastUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Positions the user has saved by name, stored in the Diplomacy Lab JSON position format.
CREATE TABLE IF NOT EXISTS `wD_LabPositions` (
  `id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `userID` mediumint(8) unsigned NOT NULL,
  `name` varchar(120) NOT NULL DEFAULT '',
  `variantID` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `positionJSON` mediumtext NOT NULL,
  `timeSaved` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `userID` (`userID`,`timeSaved`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
