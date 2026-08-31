import { current } from "@reduxjs/toolkit";
import GameDataResponse from "../../../../../state/interfaces/GameDataResponse";
import GameOverviewResponse from "../../../../../state/interfaces/GameOverviewResponse";
import { GameState } from "../../../../../state/interfaces/GameState";
import { handleGetSucceeded, handleGetFailed } from "../handleSucceededFailed";

/* eslint-disable no-param-reassign */
export default function fetchGameStatusFulfilled(
  state: GameState,
  action,
): void {
  if (!action.payload) {
    handleGetFailed(state, action);
    return;
  }
  // console.log("fetchGameStatusFulfilled");
  handleGetSucceeded(state);
  // If this is the initial update, then jump to the most recent state upon load
  if (state.status.phases.length <= 0 && action.payload.phases.length > 0) {
    state.viewedPhaseState.viewedPhaseIdx = action.payload.phases.length - 1;
    state.viewedPhaseState.latestPhaseViewed = action.payload.phases.length - 1;
  }

  state.status = action.payload;

  // A game's history can get shorter as well as longer: the Lab rebuilds a board from an earlier
  // position, which takes the phases after it with it. The phase being viewed has to come back
  // inside the list, or the board is left pointing at a phase that is no longer there.
  const lastPhaseIdx = Math.max(action.payload.phases.length - 1, 0);
  if (state.viewedPhaseState.viewedPhaseIdx > lastPhaseIdx)
    state.viewedPhaseState.viewedPhaseIdx = lastPhaseIdx;
  if (state.viewedPhaseState.latestPhaseViewed > lastPhaseIdx)
    state.viewedPhaseState.latestPhaseViewed = lastPhaseIdx;

  const {
    data: { data },
    overview: { members },
  }: {
    data: { data: GameDataResponse["data"] };
    overview: {
      members: GameOverviewResponse["members"];
    };
  } = current(state);
}
