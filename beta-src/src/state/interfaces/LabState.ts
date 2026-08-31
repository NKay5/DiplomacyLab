/**
 * Diplomacy Lab's own state, alongside the game state the board already keeps.
 *
 * In the Lab the board is the whole application: the same map is used to build a position and then
 * to play it out. `mode` is what separates the two. In "orders" mode the board behaves exactly as
 * webDiplomacy's board always has; in "edit" mode a click changes the position instead of starting
 * an order.
 */

/** What a click on the board does. */
export type LabMode = "orders" | "edit";

/** What a click places while editing. */
export type LabTool = "unit" | "center" | "erase";

/** Which kind of unit to place. "Auto" lets the province decide, and turns an army on a coast
 *  into a fleet when clicked again. */
export type LabUnitType = "Auto" | "Army" | "Fleet";

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
}
