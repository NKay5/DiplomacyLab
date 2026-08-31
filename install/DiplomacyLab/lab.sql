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

-- The analysis tree.
--
-- A scenario is a line of play being studied. It holds one or more branches; each branch is a
-- sequence of nodes, and each node is one position - a phase, exactly as the engine left it.
-- Adjudicating the last node of a branch appends its successor; adjudicating any earlier node
-- starts a new branch instead, so going back and trying something else never rewrites what has
-- already been played.
--
-- These tables are the record that matters. The sandbox games behind the branches are working
-- space: a branch's game holds whichever position is being looked at, and is rebuilt from the node
-- whenever that changes.
CREATE TABLE IF NOT EXISTS `wD_LabScenarios` (
  `id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `userID` mediumint(8) unsigned NOT NULL,
  `name` varchar(120) NOT NULL DEFAULT '',
  `variantID` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `currentBranchID` mediumint(8) unsigned DEFAULT NULL,
  `timeCreated` int(10) unsigned NOT NULL DEFAULT 0,
  `timeLastUsed` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `userID` (`userID`,`timeLastUsed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- One branch of a scenario: the sandbox game it plays on, the position it ends at, and the
-- position it is currently showing.
CREATE TABLE IF NOT EXISTS `wD_LabBranches` (
  `id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `scenarioID` mediumint(8) unsigned NOT NULL,
  `gameID` mediumint(8) unsigned NOT NULL,
  `name` varchar(60) NOT NULL DEFAULT '',
  `parentNodeID` mediumint(8) unsigned DEFAULT NULL,
  `headNodeID` mediumint(8) unsigned DEFAULT NULL,
  `currentNodeID` mediumint(8) unsigned DEFAULT NULL,
  `timeCreated` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `gameID` (`gameID`),
  KEY `scenarioID` (`scenarioID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- One position, in the Diplomacy Lab JSON position format, and the position it came from.
CREATE TABLE IF NOT EXISTS `wD_LabNodes` (
  `id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `scenarioID` mediumint(8) unsigned NOT NULL,
  `branchID` mediumint(8) unsigned NOT NULL,
  `parentNodeID` mediumint(8) unsigned DEFAULT NULL,
  `turn` smallint(5) NOT NULL DEFAULT 0,
  `phase` varchar(20) NOT NULL DEFAULT 'Diplomacy',
  `positionJSON` mediumtext NOT NULL,
  `timeCreated` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `branchID` (`branchID`,`id`),
  KEY `scenarioID` (`scenarioID`),
  KEY `parentNodeID` (`parentNodeID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
