/**
 * Diplomacy Lab's own state, alongside the game state the board already keeps.
 *
 * In the Lab the board is the whole application: the same map is used to build a position, to play
 * it out, and to walk back through what has been played. `mode` is what separates building from
 * playing. In "orders" mode the board behaves exactly as webDiplomacy's board always has; in
 * "edit" mode a click changes the position instead of starting an order.
 *
 * The rest is the analysis itself. A scenario holds branches; a branch is a sequence of positions;
 * the board is always showing one of them. The server owns all of it - this is only what has been
 * read back, so that the navigation bar can be drawn.
 */

/** What a click on the board does. */
export type LabMode = "orders" | "edit";

/** What a click places while editing. */
export type LabTool = "unit" | "center" | "erase";

/** Which kind of unit to place. "Auto" lets the province decide, and turns an army on a coast
 *  into a fleet when clicked again. */
export type LabUnitType = "Auto" | "Army" | "Fleet";

/** One position in a branch. */
export interface LabNode {
  id: number;
  branchID: number;
  parentNodeID: number | null;
  turn: number;
  phase: string;
  year: number;
  season: string;
  /** How it reads on the navigation bar, eg "Spring 1901 Movement". */
  label: string;
}

/** One line of play within a scenario, and the board it is played on. */
export interface LabBranch {
  id: number;
  name: string;
  gameID: number;
  /** The position this branch was started from, on whichever branch that was. */
  parentNodeID: number | null;
  headNodeID: number | null;
  currentNodeID: number | null;
  nodes: LabNode[];
}

/** Where the board is in the analysis right now. */
export interface LabPlace {
  scenarioID: number;
  scenarioName: string;
  branchID: number;
  branchName: string;
  gameID: number;
  nodeID: number;
  headNodeID: number;
  /** Whether this is the last position of its branch, and so can be played on directly. */
  atHead: boolean;
}

export default interface LabState {
  /** Whether this board is a Lab position at all. Set from the page the board was opened from. */
  enabled: boolean;
  mode: LabMode;
  tool: LabTool;
  /** The power being placed, or 0 for neutral / nobody, which clears a province. */
  countryID: number;
  unitType: LabUnitType;
  /**
   * Non-null while a Lab request is in flight, naming what it is doing, so the board can show it
   * is working and keep the buttons that change the whole position out of reach until it is done.
   */
  busy: string | null;
  /** The last thing that went wrong, shown to the user and then cleared. */
  error: string | null;
  /** Something worth mentioning that is not a problem, such as a new branch having been started. */
  notice: string | null;
  /** The scenario's branches, in the order they were created. Empty until the tree is read. */
  branches: LabBranch[];
  /** Where the board is, or null before the tree has been read. */
  place: LabPlace | null;
  /** Whether the position on the board may be edited. */
  canEdit: boolean;
}
