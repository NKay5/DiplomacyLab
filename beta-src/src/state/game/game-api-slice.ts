import { createSlice, createAsyncThunk, current } from "@reduxjs/toolkit";
import ApiRoute from "../../enums/ApiRoute";
import {
  getGameApiRequest,
  postGameApiRequest,
  submitOrders,
} from "../../utils/api";
import GameDataResponse from "../interfaces/GameDataResponse";
import GameErrorResponse from "../interfaces/GameErrorResponse";
import GameOverviewResponse from "../interfaces/GameOverviewResponse";
import { ApiStatus, GameState } from "../interfaces/GameState";
import GameStatusResponse from "../interfaces/GameStatusResponse";
import GameMessages, { MessageStatus } from "../interfaces/GameMessages";
import ViewedPhaseState from "../interfaces/ViewedPhaseState";
import { RootState } from "../store";
import initialState from "./initial-state";
import OrdersMeta from "../interfaces/SavedOrders";
import OrderState from "../interfaces/OrderState";
import mergeMessageArrays from "../../utils/state/mergeMessageArrays";
import updateOrder from "../../utils/state/updateOrder";
import updateOrdersMeta from "../../utils/state/updateOrdersMeta";
import UpdateOrdersMetaAction from "../../interfaces/state/UpdateOrdersMetaAction";
import SavedOrdersConfirmation from "../../interfaces/state/SavedOrdersConfirmation";
import OrderSubmission from "../../interfaces/state/OrderSubmission";
import resetOrder from "../../utils/state/resetOrder";
import processMapClick from "../../utils/state/gameApiSlice/reducers/processMapClick";
import fetchGameDataFulfilled from "../../utils/state/gameApiSlice/extraReducers/fetchGameData/fulfilled";
import TerritoriesMeta from "../interfaces/TerritoriesState";
import fetchGameOverviewFulfilled from "../../utils/state/gameApiSlice/extraReducers/fetchGameOverview/fulfilled";
import fetchGameStatusFulfilled from "../../utils/state/gameApiSlice/extraReducers/fetchGameStatus/fulfilled";
import {
  saveOrdersPending,
  saveOrdersFulfilled,
  saveOrdersRejected,
} from "../../utils/state/gameApiSlice/extraReducers/saveOrders/fulfilled";
import shallowArraysEqual from "../../utils/shallowArraysEqual";
import { setAlert } from "../interfaces/GameAlert";
import PlayerActiveGames from "../interfaces/PlayerActiveGames";
import {
  handleGetSucceeded,
  handleGetFailed,
  handlePostSucceeded,
  handlePostFailed,
} from "../../utils/state/gameApiSlice/extraReducers/handleSucceededFailed";

export const fetchGameData = createAsyncThunk(
  ApiRoute.GAME_DATA,
  async (queryParams: { countryID?: string; gameID: string }) => {
    const { data } = await getGameApiRequest(ApiRoute.GAME_DATA, queryParams);
    // console.log("fetchGameData");
    // console.log(data);
    return data as GameDataResponse;
  },
);

export const fetchGameOverview = createAsyncThunk(
  ApiRoute.GAME_OVERVIEW,
  async (queryParams: { gameID: string }) => {
    const {
      data: { data },
    } = await getGameApiRequest(ApiRoute.GAME_OVERVIEW, queryParams);
    // console.log("fetchGameOverview");
    // console.log(data);
    return data as GameOverviewResponse;
  },
);

export const fetchGameStatus = createAsyncThunk(
  ApiRoute.GAME_STATUS,
  async (queryParams: { countryID?: string; gameID: string }) => {
    const { data } = await getGameApiRequest(ApiRoute.GAME_STATUS, queryParams);
    // console.log("fetchGameStatus");
    // console.log(data);
    return data as GameStatusResponse;
  },
);

export const fetchGameMessages = createAsyncThunk(
  ApiRoute.GAME_MESSAGES,
  async (queryParams: {
    gameID: string;
    countryID?: string;
    sinceTime?: string;
  }) => {
    const {
      data: { data },
    } = await getGameApiRequest(
      ApiRoute.GAME_MESSAGES,
      queryParams,
      // set a 60 second timeout.
      // Timeout is important because we rate-limit to
      // one outstanding request at a time.
      60000,
    );
    return data as GameMessages;
  },
);

export const fetchPlayerActiveGames = createAsyncThunk(
  ApiRoute.PLAYERS_ACTIVE_GAMES,
  async () => {
    const { data } = await getGameApiRequest(ApiRoute.PLAYERS_ACTIVE_GAMES, {});
    return data as { games: PlayerActiveGames };
  },
);

export const sendMessage = createAsyncThunk(
  ApiRoute.SEND_MESSAGE,
  async (queryParams: {
    gameID: string;
    countryID: string;
    toCountryID: string;
    message: string;
  }) => {
    const response = await postGameApiRequest(
      ApiRoute.SEND_MESSAGE,
      queryParams,
    );
    return response.data as unknown as GameMessages;
  },
);

export const setVoteStatus = createAsyncThunk(
  ApiRoute.GAME_SETVOTE,
  async (queryParams: {
    countryID: string;
    gameID: string;
    vote: string;
    voteOn: string;
  }) => {
    const { data } = await postGameApiRequest(
      ApiRoute.GAME_SETVOTE,
      queryParams,
    );
    return data;
  },
);

export const copySandboxFromGame = createAsyncThunk(
  ApiRoute.SANDBOX_COPY,
  async (queryParams: { copyGameID: string }) => {
    const { data } = await getGameApiRequest(
      ApiRoute.SANDBOX_COPY,
      queryParams,
    );
    return data;
  },
);

export const moveSandboxTurnBack = createAsyncThunk(
  ApiRoute.SANDBOX_MOVETURNBACK,
  async (queryParams: { gameID: string }) => {
    const { data } = await getGameApiRequest(
      ApiRoute.SANDBOX_MOVETURNBACK,
      queryParams,
    );
    return data;
  },
);

export const deleteSandbox = createAsyncThunk(
  ApiRoute.SANDBOX_DELETE,
  async (queryParams: { gameID: string }) => {
    const { data } = await getGameApiRequest(
      ApiRoute.SANDBOX_DELETE,
      queryParams,
    );
    return data;
  },
);

export const markMessagesSeen = createAsyncThunk(
  ApiRoute.MESSAGES_SEEN,
  async (queryParams: {
    countryID: string;
    gameID: string;
    seenCountryID: string;
  }) => {
    const { data } = await postGameApiRequest(
      ApiRoute.MESSAGES_SEEN,
      queryParams,
    );
    return data;
  },
);

export const markBackFromLeft = createAsyncThunk(
  ApiRoute.SET_BACK_FROM_LEFT,
  async (queryParams: { countryID: string; gameID: string }) => {
    const { data } = await postGameApiRequest(
      ApiRoute.SET_BACK_FROM_LEFT,
      queryParams,
    );
    return data;
  },
);

export const saveOrders = createAsyncThunk(
  "game/submitOrders",
  async (data: OrderSubmission, thunkAPI) => {
    const formData = new FormData();
    formData.set("orderUpdates", JSON.stringify(data.orderUpdates));
    formData.set("context", data.context);
    formData.set("contextKey", data.contextKey);
    let response;
    try {
      response = await submitOrders(formData, data.queryParams);
    } catch (e) {
      console.log("Exception submitting orders");
      console.log(e);
      const result: SavedOrdersConfirmation = {
        invalid: true,
        notice:
          "Error saving orders, no server response or network connection timed out",
        orders: {},
      };
      // Reject this value because it indicates an error with the connection itself
      return thunkAPI.rejectWithValue(result);
    }
    // console.log({ response });
    // Sometimes webdip sends back a response that doesn't have the "x-json" header at all,
    // instead it has an HTML page displaying an error message.
    // We're of course not going to try to render a whole HTML page, so instead we simply
    // manually construct an error message.
    const confirmation: string = response.headers["x-json"];
    if (!confirmation) {
      const result: SavedOrdersConfirmation = {
        invalid: true,
        notice:
          "Error saving orders, no server response or game already advanced to next phase",
        orders: {},
      };
      // Return this value normally without rejecting it since it's not a problem
      // with the connection, it's webdip declaring our order illegal or something like that.
      return result;
    }
    const parsed: SavedOrdersConfirmation = JSON.parse(
      confirmation.substring(1, confirmation.length - 1),
    );
    return parsed;
  },
);

export const loadGameData =
  (gameID: string, countryID?: string) => async (dispatch) => {
    await Promise.all([
      dispatch(fetchGameData({ gameID, countryID })),
      dispatch(fetchGameStatus({ gameID, countryID })),
      dispatch(fetchPlayerActiveGames()),
    ]);
  };

export const loadGame = (gameID: string) => async (dispatch) => {
  const response = await dispatch(
    fetchGameOverview({
      gameID,
    }),
  );
  if (response.payload) {
    const countryID = response.payload.user?.member.countryID;
    const { phase } = response.payload;
    if (phase === "Pre-game") {
      return;
    }

    await loadGameData(gameID, countryID);
  }
};

/*
 * Diplomacy Lab.
 *
 * The Lab drives the position from the board itself, so these are the actions the board needs
 * beyond ordering: change a province, adjudicate, step back, branch, and keep a copy. Each one is
 * a thin call to the lab/* API, which does all the work, followed by a reload, so that what the
 * board shows is always what the server actually holds.
 */

/* eslint-disable @typescript-eslint/no-explicit-any */
/**
 * Why the server refused a Lab request, in its own words.
 *
 * webDiplomacy's API answers a refusal with plain text and an error status ("Munich cannot hold a
 * fleet."), which is exactly what belongs on the board. Anything else means the request never got
 * an answer, so the transport's message is the best there is.
 */
const labRefusalReason = (error: any): string => {
  const body = error?.response?.data;
  if (typeof body === "string" && body.trim()) return body.trim();
  if (body?.msg) return body.msg;
  return error?.message || "That change could not be made.";
};

/**
 * Make a lab/* request and bring the board up to date with what the server now holds.
 *
 * Reloading the board is part of the request rather than something that happens afterwards, and
 * Lab requests are run strictly one after another. Building a position means clicking province
 * after province, often faster than a round trip; left to overlap, two reads of the game would be
 * in flight at once and the board would end up showing whichever answered last, which is not
 * necessarily the newer one. Queueing keeps every click - none is dropped, and the board always
 * ends up showing the position as it now stands.
 *
 * `reload` says how much has changed: an edit only moves units about, while adjudicating also
 * changes the season, the phase and whose turn it is, which the board reads from the overview.
 */
let labPending: Promise<unknown> = Promise.resolve();

const labRequest = (
  route: ApiRoute,
  queryParams: { [key: string]: string },
  { dispatch, getState, rejectWithValue }: any,
  reload: "position" | "everything" = "position",
): Promise<any> => {
  const run = async () => {
    let data;

    try {
      ({ data } = await getGameApiRequest(route, queryParams));
    } catch (error) {
      return rejectWithValue(labRefusalReason(error));
    }

    const { overview } = (getState() as RootState).game;
    const gameID = queryParams.gameID || String(overview.gameID);

    if (gameID && gameID !== "0") {
      if (reload === "everything")
        await dispatch(fetchGameOverview({ gameID }));

      const countryID = (getState() as RootState).game.overview.user?.member
        .countryID;
      await dispatch(
        loadGameData(gameID, countryID ? String(countryID) : undefined),
      );
    }

    return data;
  };

  const result = labPending.then(run, run);
  labPending = result.catch(() => undefined);

  return result;
};

/** The message to show when a Lab request fails. */
const labErrorMessage = (action: any): string =>
  (typeof action?.payload === "string" ? action.payload : "") ||
  action?.payload?.msg ||
  action?.error?.message ||
  "That change could not be made.";
/* eslint-enable @typescript-eslint/no-explicit-any */

export const labEditProvince = createAsyncThunk(
  ApiRoute.LAB_EDIT_PROVINCE,
  async (
    queryParams: {
      gameID: string;
      terrID: string;
      tool: string;
      countryID: string;
      unitType: string;
    },
    thunkAPI,
  ) => labRequest(ApiRoute.LAB_EDIT_PROVINCE, queryParams, thunkAPI),
);

export const labResolve = createAsyncThunk(
  ApiRoute.LAB_RESOLVE,
  async (queryParams: { gameID: string }, thunkAPI) =>
    labRequest(ApiRoute.LAB_RESOLVE, queryParams, thunkAPI, "everything"),
);

export const labReset = createAsyncThunk(
  ApiRoute.LAB_RESET,
  async (queryParams: { gameID: string }, thunkAPI) =>
    labRequest(ApiRoute.LAB_RESET, queryParams, thunkAPI, "everything"),
);

export const labDuplicate = createAsyncThunk(
  ApiRoute.LAB_DUPLICATE,
  async (
    queryParams: { gameID: string; name: string },
    { rejectWithValue },
  ) => {
    // The copy is a different board, which the browser is about to be sent to, so there is nothing
    // here to bring up to date.
    try {
      const { data } = await getGameApiRequest(
        ApiRoute.LAB_DUPLICATE,
        queryParams,
      );
      return data;
    } catch (error) {
      return rejectWithValue(labRefusalReason(error));
    }
  },
);

export const labSavePosition = createAsyncThunk(
  ApiRoute.LAB_SAVE,
  async (
    queryParams: { gameID: string; name: string },
    { rejectWithValue },
  ) => {
    // Saving copies the position aside; the board itself is unchanged.
    try {
      const { data } = await getGameApiRequest(ApiRoute.LAB_SAVE, queryParams);
      return data;
    } catch (error) {
      return rejectWithValue(labRefusalReason(error));
    }
  },
);

/**
 * createSlice handles state changes properly without reassiging state, but
 * eslint does not know this. therefore, no-param-reassign is disabled for
 * the createSlice block of code below or functions therein.
 */

/* eslint-disable no-param-reassign */

/** Put the board on the newest phase there is, whatever it was looking at before. */
const showLatestPhase = (state: GameState): void => {
  const latest = Math.max(state.status.phases.length - 1, 0);

  state.viewedPhaseState.viewedPhaseIdx = latest;
  state.viewedPhaseState.latestPhaseViewed = latest;
};

/**
 * Leave edit mode if the board has moved to a phase that cannot be edited.
 *
 * Retreats and adjustments follow from the moves before them, so there is nothing to hand-edit
 * there; landing on one with the editor still open would offer a click that only ever fails.
 */
const settleLabMode = (state: GameState): void => {
  if (state.lab.mode === "edit" && state.overview.phase !== "Diplomacy")
    state.lab.mode = "orders";
};

const gameApiSlice = createSlice({
  name: "game",
  initialState,
  reducers: {
    resetOrder,
    updateOrder(state, action) {
      updateOrder(state, action.payload);
    },
    updateOrdersMeta(state, action: UpdateOrdersMetaAction) {
      updateOrdersMeta(state, action.payload);
    },
    updateTerritoriesMeta(state, action) {
      state.territoriesMeta = action.payload;
    },
    processMapClick,
    labSetMode(state, action) {
      state.lab.mode = action.payload;
      // Leaving edit mode must not leave a half-entered order behind, and entering it must not
      // leave one showing on the map.
      resetOrder(state);
      // Start editing with a power chosen, so the first click puts something on the board.
      // Neutral is still there to pick, and it is what empties a province.
      if (action.payload === "edit" && !state.lab.countryID) {
        const countryIDs = state.overview.members.map((m) => m.countryID);
        if (countryIDs.length) state.lab.countryID = Math.min(...countryIDs);
      }
    },
    labSetTool(state, action) {
      state.lab.tool = action.payload;
    },
    labSetCountry(state, action) {
      state.lab.countryID = action.payload;
    },
    labSetUnitType(state, action) {
      state.lab.unitType = action.payload;
    },
    labSetEnabled(state, action) {
      state.lab.enabled = action.payload;
    },
    labClearError(state) {
      state.lab.error = null;
    },
    processMessagesSeen(state, action) {
      const countryID = action.payload;
      state.messages.newMessagesFrom = state.messages.newMessagesFrom.filter(
        (e) => e !== countryID,
      );
      state.messages.messages
        .filter((m) => [m.fromCountryID, m.toCountryID].includes(countryID))
        .forEach((m) => {
          m.status = MessageStatus.READ;
        });
    },
    setNeedsGameOverview(state, action) {
      state.needsGameOverview = action.payload;
    },
    setNeedsGameData(state, action) {
      state.needsGameData = action.payload;
    },
    changeViewedPhaseIdxBy(state, action) {
      let newIdx = state.viewedPhaseState.viewedPhaseIdx + action.payload;
      newIdx = Math.min(newIdx, state.status.phases.length - 1);
      newIdx = Math.max(newIdx, 0);
      state.viewedPhaseState.viewedPhaseIdx = newIdx;
      state.viewedPhaseState.latestPhaseViewed = Math.max(
        state.viewedPhaseState.latestPhaseViewed,
        newIdx,
      );
    },
    setViewedPhase(state, action) {
      const newIdx = action.payload;
      state.viewedPhaseState.viewedPhaseIdx = newIdx;
      state.viewedPhaseState.latestPhaseViewed = Math.max(
        state.viewedPhaseState.latestPhaseViewed,
        newIdx,
      );
    },
    setViewedPhaseToLatestPhaseViewed(state) {
      state.viewedPhaseState.viewedPhaseIdx =
        state.viewedPhaseState.latestPhaseViewed;
    },
    setViewedPhaseToLatest(state) {
      state.viewedPhaseState.viewedPhaseIdx = state.status.phases.length - 1;
    },
    setAlert(state, action) {
      setAlert(state.alert, action.payload);
    },
    hideAlert(state, action) {
      state.alert.visible = false;
    },
    selectMessageCountryID(state, action) {
      state.messages.countryIDSelected = action.payload;
    },
  },
  extraReducers(builder) {
    builder
      // fetchGameData
      .addCase(fetchGameData.pending, (state) => {
        // console.log("fetchGameData pending!");
        state.apiStatus = "loading";
      })
      .addCase(fetchGameData.fulfilled, fetchGameDataFulfilled)
      .addCase(fetchGameData.rejected, (state, action) => {
        // console.log("fetchGameData rejected!");
        handleGetFailed(state, action);
      })
      // fetchGameOverview
      .addCase(fetchGameOverview.pending, (state) => {
        state.outstandingOverviewRequests = true;
        state.apiStatus = "loading";
      })
      .addCase(fetchGameOverview.fulfilled, fetchGameOverviewFulfilled)
      .addCase(fetchGameOverview.rejected, (state, action) => {
        state.outstandingOverviewRequests = false;
        handleGetFailed(state, action);
      })
      // fetchGameStatus
      .addCase(fetchGameStatus.pending, (state) => {
        state.apiStatus = "loading";
      })
      .addCase(fetchGameStatus.fulfilled, fetchGameStatusFulfilled)
      .addCase(fetchGameStatus.rejected, (state, action) => {
        handleGetFailed(state, action);
      })
      /*
       * Diplomacy Lab. Every Lab action ends the same way: the board asks the server for the
       * position again, so what is drawn is always what was actually stored. The only state kept
       * here is whether a request is in flight, and the reason if one failed.
       */
      .addCase(labEditProvince.pending, (state) => {
        state.lab.busy = "edit";
        state.lab.error = null;
      })
      // The thunks reload the board themselves before they finish, so by the time they are
      // fulfilled there is nothing left to ask for.
      .addCase(labEditProvince.fulfilled, (state) => {
        state.lab.busy = null;
      })
      .addCase(labEditProvince.rejected, (state, action) => {
        state.lab.busy = null;
        state.lab.error = labErrorMessage(action);
      })
      .addCase(labResolve.pending, (state) => {
        state.lab.busy = "resolve";
        state.lab.error = null;
      })
      .addCase(labResolve.fulfilled, (state) => {
        state.lab.busy = null;
        // Adjudicating leaves the board on the phase that was just resolved, showing the arrows
        // and bounces of what happened. In the Lab the point is to see where that leaves the
        // position, so the board moves on to it; the phase controls step back to the results.
        showLatestPhase(state);
        settleLabMode(state);
      })
      .addCase(labResolve.rejected, (state, action) => {
        state.lab.busy = null;
        state.lab.error = labErrorMessage(action);
      })
      .addCase(labReset.pending, (state) => {
        state.lab.busy = "reset";
        state.lab.error = null;
      })
      .addCase(labReset.fulfilled, (state) => {
        state.lab.busy = null;
        showLatestPhase(state);
        settleLabMode(state);
      })
      .addCase(labReset.rejected, (state, action) => {
        state.lab.busy = null;
        state.lab.error = labErrorMessage(action);
      })
      .addCase(labSavePosition.pending, (state) => {
        state.lab.busy = "save";
        state.lab.error = null;
      })
      .addCase(labSavePosition.fulfilled, (state) => {
        state.lab.busy = null;
      })
      .addCase(labSavePosition.rejected, (state, action) => {
        state.lab.busy = null;
        state.lab.error = labErrorMessage(action);
      })
      .addCase(labDuplicate.pending, (state) => {
        state.lab.busy = "duplicate";
        state.lab.error = null;
      })
      .addCase(labDuplicate.fulfilled, (state) => {
        state.lab.busy = null;
      })
      .addCase(labDuplicate.rejected, (state, action) => {
        state.lab.busy = null;
        state.lab.error = labErrorMessage(action);
      })
      .addCase(fetchPlayerActiveGames.fulfilled, (state, action) => {
        if (typeof action.payload.games !== "undefined") {
          handleGetSucceeded(state);
          state.activeGames = action.payload.games;
        } else {
          handleGetFailed(state, action);
        }
      })
      // saveOrders
      .addCase(saveOrders.pending, saveOrdersPending)
      .addCase(saveOrders.fulfilled, saveOrdersFulfilled)
      .addCase(saveOrders.rejected, saveOrdersRejected)
      // setVoteStatus
      .addCase(setVoteStatus.pending, (state, action) => {
        state.apiStatus = "loading";
        const { vote, voteOn } = action.meta.arg;
        state.votingInProgress = { ...state.votingInProgress, [vote]: voteOn };
      })
      .addCase(setVoteStatus.fulfilled, (state, action) => {
        const { vote } = action.meta.arg;
        state.votingInProgress = { ...state.votingInProgress, [vote]: null };
        handlePostSucceeded(state);
        if (state.overview.user) {
          const newVotes = action.payload.split(",").filter((s) => !!s);
          state.overview.user.member.votes = newVotes;
          state.overview.members.forEach((member) => {
            if (member.countryID === state.overview.user?.member.countryID) {
              member.votes = newVotes;
            }
          });
        }
      })
      .addCase(setVoteStatus.rejected, (state, action) => {
        handlePostFailed(state, "Error sending vote, network connection issue");
        const { vote } = action.meta.arg;
        state.votingInProgress = { ...state.votingInProgress, [vote]: null };
      })
      .addCase(markBackFromLeft.fulfilled, (state, action) => {
        state.status.status = "Playing";
      })
      // Send message
      .addCase(sendMessage.fulfilled, (state, action) => {
        if (action.payload) {
          handlePostSucceeded(state);
          const { messages } = action.payload;
          const allMessages = mergeMessageArrays(
            state.messages.messages,
            messages,
          );
          state.messages.messages = allMessages;
        } else {
          handlePostFailed(
            state,
            "Error sending message, network connection issue",
          );
        }
      })
      .addCase(sendMessage.rejected, (state, action) => {
        handlePostFailed(
          state,
          "Error sending message, network connection issue",
        );
      })
      // Fetch Game Messages
      .addCase(fetchGameMessages.pending, (state, action) => {
        state.outstandingMessageRequests = true;
      })
      .addCase(fetchGameMessages.rejected, (state, action) => {
        state.outstandingMessageRequests = false;
        handleGetFailed(state, action);
      })
      .addCase(fetchGameMessages.fulfilled, (state, action) => {
        state.outstandingMessageRequests = false;
        if (action.payload) {
          handleGetSucceeded(state);
          const { messages, newMessagesFrom, time } = action.payload;
          if (messages) {
            const messagesWithStatus = messages.map((m) => {
              return {
                ...m,
                status:
                  // eslint-disable-next-line no-nested-ternary
                  newMessagesFrom.includes(m.fromCountryID) ||
                  newMessagesFrom.includes(m.toCountryID) // needed for ALL
                    ? state.messages.time === 0
                      ? MessageStatus.UNKNOWN
                      : MessageStatus.UNREAD
                    : MessageStatus.READ,
              };
            });
            const allMessages = mergeMessageArrays(
              state.messages.messages,
              messagesWithStatus,
            );
            if (state.messages.messages.length !== allMessages.length) {
              state.messages.messages = allMessages;
            }
          }
          if (newMessagesFrom) {
            // Only use the new newMessagesFrom if it has distinct values
            // Otherwise, use the old value to preserve reference equality
            // so that selectors recognize nothing changed and less of the UI
            // needs to redraw
            if (
              !shallowArraysEqual(
                state.messages.newMessagesFrom,
                newMessagesFrom,
              )
            ) {
              state.messages.newMessagesFrom = newMessagesFrom;
            }
          }
          if (time) {
            // console.log(`Messages fetched at time=${time}`);
            state.messages.time = time;
          }
        } else {
          handleGetFailed(state, action);
        }
      });
  },
});
/* eslint-enable no-param-reassign */

export const gameApiSliceActions = gameApiSlice.actions;

export const gameApiStatus = ({ game: { apiStatus } }: RootState): ApiStatus =>
  apiStatus;
export const gameData = ({ game: { data } }: RootState): GameDataResponse =>
  data;
export const gameError = ({ game: { error } }: RootState): GameErrorResponse =>
  error;
export const gameOverview = ({
  game: { overview },
}: RootState): GameOverviewResponse => overview;
export const gameStatus = ({
  game: { status },
}: RootState): GameStatusResponse => status;
export const gameOrdersMeta = ({
  game: { ordersMeta },
}: RootState): OrdersMeta => ordersMeta;
export const gameOrder = ({ game: { order } }: RootState): OrderState => order;
export const gameTerritoriesMeta = ({
  game: { territoriesMeta },
}: RootState): TerritoriesMeta => territoriesMeta;
// gameMessages considered harmful, because part of the GameMessages object is a
// counter that tracks the last query timestamp, which means that if you use this
// selector rather than a more specific one, your component will update basically
// every time the server is queries for messages, regardless of whether
// the messages changed or not.
// export const gameMessages = ({ game: { messages } }: RootState): GameMessages =>
//  messages;
export const gameMaps = ({ game: { maps } }: RootState) => maps;
export const gameViewedPhase = ({
  game: { viewedPhaseState },
}: RootState): ViewedPhaseState => viewedPhaseState;
export const gameLegalOrders = ({ game: { legalOrders } }: RootState) =>
  legalOrders;
export const gameAlert = ({ game: { alert } }: RootState) => alert;
export const gameLab = ({ game: { lab } }: RootState) => lab;
export const playerActiveGames = ({ game: { activeGames } }: RootState) =>
  activeGames;
export default gameApiSlice.reducer;
