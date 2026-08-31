import React, { useEffect } from "react";
import useLocalStorageState from "use-local-storage-state";
import WDButton from "./WDButton";
import { useAppDispatch, useAppSelector } from "../../state/hooks";
import {
  gameApiSliceActions,
  gameData,
  gameLab,
  gameOrdersMeta,
  gameOverview,
  gameStatus,
  gameViewedPhase,
  labReady,
  saveOrders,
} from "../../state/game/game-api-slice";
import UpdateOrder from "../../interfaces/state/UpdateOrder";
import { RootState } from "../../state/store";
import { OrderStatus } from "../../interfaces/state/MemberData";
import OrderSubmission from "../../interfaces/state/OrderSubmission";
import useSettings from "../../hooks/useSettings";

enum OrderStatusButton {
  SAVE = "save",
  READY = "ready",
}

interface WDOrderStatsControlsProps {
  orderStatus: OrderStatus;
}

const WDOrderStatusControls: React.FC<WDOrderStatsControlsProps> = function ({
  orderStatus,
}): React.ReactElement {
  const { settings } = useSettings();

  const overview = useAppSelector(gameOverview);
  const { data } = useAppSelector(gameData);
  const ordersMeta = useAppSelector(gameOrdersMeta);
  const status = useAppSelector(gameStatus);
  const viewedPhaseState = useAppSelector(gameViewedPhase);
  const lab = useAppSelector(gameLab);
  const savingOrdersInProgress = useAppSelector(
    (state) => state.game.savingOrdersInProgress,
  );

  const viewingCurPhase =
    viewedPhaseState.viewedPhaseIdx >= status.phases.length - 1;

  const currentOrderInProgress = useAppSelector(
    ({ game: { order } }: RootState) => order.inProgress,
  );

  const dispatch = useAppDispatch();

  const ordersMetaValues = Object.values(ordersMeta);
  const ordersLength = ordersMetaValues.length;
  const ordersSaved = ordersMetaValues.reduce(
    (acc, meta) => acc + +meta.saved,
    0,
  );

  let readyEnabled: boolean;
  let saveEnabled: boolean;
  let readyButtonText: string;
  let saveButtonText: string;
  const saveText = "Save";
  const { user } = overview;
  const extraSCs = user ? user.member.supplyCenterNo - user.member.unitNo : 0;
  const canSave =
    ordersLength > 0 &&
    (overview.phase === "Diplomacy" ||
      ordersLength !== ordersSaved ||
      (overview.phase === "Builds" && extraSCs > 0));

  // orderStatus contains what the server thinks our order status is.
  if (lab.enabled) {
    // In the Lab one person enters every power's orders, so there is nobody to wait for and
    // nothing to be ready *for*: this button is what adjudicates. It saves whatever is on the
    // board and hands it straight to the engine, so the result is always of the orders on screen.
    readyEnabled = viewingCurPhase && !lab.busy && !savingOrdersInProgress;
    saveEnabled = viewingCurPhase && canSave;
    readyButtonText = lab.busy === "ready" ? "Adjudicating..." : "Ready";
    saveButtonText =
      savingOrdersInProgress === "saving" ? "Saving..." : saveText;
  } else if (savingOrdersInProgress === "readying") {
    readyEnabled = false;
    saveEnabled = false;
    readyButtonText = "Readying...";
    saveButtonText = saveText;
  } else if (savingOrdersInProgress === "unreadying") {
    readyEnabled = false;
    saveEnabled = false;
    readyButtonText = "Unreadying...";
    saveButtonText = saveText;
  } else if (savingOrdersInProgress === "saving") {
    readyEnabled = false;
    saveEnabled = false;
    readyButtonText = "Ready";
    saveButtonText = "Saving...";
  } else if (orderStatus.Ready) {
    readyEnabled = viewingCurPhase;
    saveEnabled = false;
    readyButtonText = "Unready";
    saveButtonText = saveText;
  } else if (orderStatus.Saved || orderStatus.Completed) {
    readyEnabled = viewingCurPhase;
    saveEnabled = ordersLength !== ordersSaved && viewingCurPhase;
    readyButtonText = "Ready";
    saveButtonText = saveText;
  } else {
    readyEnabled = viewingCurPhase && canSave;
    saveEnabled = viewingCurPhase && canSave;
    readyButtonText = "Ready";
    saveButtonText = saveText;
  }

  const doAnimateGlow =
    saveEnabled && ordersLength !== ordersSaved && !currentOrderInProgress;

  /** Adjudicate this Lab board, once the orders on it have reached the server. */
  const adjudicateLab = () => {
    dispatch(labReady({ gameID: String(overview.gameID) })).then((action) => {
      const place = action.payload as
        | { gameID?: number; branchCreated?: string | null }
        | undefined;
      // Playing a different continuation from an earlier position starts a new branch, which has
      // a board of its own; the notice travels with the browser so it can be shown on arrival.
      if (place?.gameID && String(place.gameID) !== String(overview.gameID)) {
        const branch = place.branchCreated
          ? `&newBranch=${encodeURIComponent(place.branchCreated)}`
          : "";
        window.location.search = `?gameID=${place.gameID}&lab=1${branch}`;
      }
    });
  };

  const clickButton = (whatButton: OrderStatusButton) => {
    // console.log("Entered save button click");
    // When you click save or ready, it should clear any actively entered order you have going,
    // and/or any of the move input flyover. It doesn't make sense to ready and have the UI
    // stay with a partially-entered order.
    dispatch(gameApiSliceActions.resetOrder());

    const isLabAdjudication =
      lab.enabled && whatButton === OrderStatusButton.READY;

    if ("currentOrders" in data && "contextVars" in data) {
      const { currentOrders, contextVars } = data;
      if (contextVars && currentOrders) {
        const orderUpdates: UpdateOrder[] = [];
        currentOrders.forEach(
          ({ fromTerrID, id, toTerrID, type: moveType, unitID, viaConvoy }) => {
            const updateReference = ordersMeta[id]?.update;
            let orderUpdate: UpdateOrder = {
              fromTerrID,
              id,
              toTerrID,
              type: moveType || "",
              unitID,
              viaConvoy,
            };
            if (updateReference) {
              orderUpdate = {
                ...orderUpdate,
                ...updateReference,
              };
            }
            orderUpdates.push(orderUpdate);
          },
        );
        const orderSubmission: OrderSubmission = {
          orderUpdates,
          context: JSON.stringify(contextVars.context),
          contextKey: contextVars.contextKey,
          queryParams: {},
          userIntent: "saving",
        };
        // The Lab never readies a member. Readying is webDiplomacy's way of waiting for the other
        // players, and it locks the board until every one of them has finished; here there is only
        // one person, and the orders are wanted now. So the orders are saved as orders, and the
        // adjudication follows once they are safely stored.
        if (whatButton === OrderStatusButton.READY && !lab.enabled) {
          if (orderStatus.Ready) {
            orderSubmission.queryParams = { notready: "on" };
            orderSubmission.userIntent = "unreadying";
          } else {
            orderSubmission.queryParams = { ready: "on" };
            orderSubmission.userIntent = "readying";
          }
        }
        // console.log({ orderSubmission });
        const saving = dispatch(saveOrders(orderSubmission));
        if (isLabAdjudication) saving.then(adjudicateLab);
        return;
      }
    }

    // A phase can legitimately have no orders at all - an empty board, or a Builds phase nobody
    // owes anything in. There is nothing to save, but there is still something to adjudicate.
    if (isLabAdjudication) adjudicateLab();
  };

  useEffect(() => {
    const needsToSave = Object.keys(ordersMeta).some(
      (key) => ordersMeta[key].saved === false,
    );
    if (needsToSave && doAnimateGlow && settings.autoSave) {
      clickButton(OrderStatusButton.SAVE);
    }
  }, [ordersMeta, settings]);

  const buttonClass = "w-14 h-14 rounded-full sm:w-fit sm:px-[30px]";

  return (
    <div className="flex flex-col sm:flex-row justify-end space-y-2 space-x-0 sm:space-x-3 sm:space-y-0 w-fit">
      {!settings.autoSave && (
        <WDButton
          color="primary"
          className={buttonClass}
          disabled={!saveEnabled}
          onClick={() => saveEnabled && clickButton(OrderStatusButton.SAVE)}
          doAnimateGlow={doAnimateGlow}
        >
          {saveButtonText}
        </WDButton>
      )}
      <WDButton
        color="primary"
        className={buttonClass}
        disabled={!readyEnabled}
        onClick={() => readyEnabled && clickButton(OrderStatusButton.READY)}
      >
        {readyButtonText}
      </WDButton>
    </div>
  );
};

export default WDOrderStatusControls;
