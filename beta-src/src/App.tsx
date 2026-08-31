import * as React from "react";

import "./assets/css/App.css";
import WDAds from "./components/ui/WDAds";
import WDMain from "./components/ui/WDMain";
import useAdPlacement from "./hooks/useAdPlacement";
import { useAppDispatch } from "./state/hooks";
import {
  gameApiSliceActions,
  labLoadTree,
  loadGame,
} from "./state/game/game-api-slice";

const App: React.FC = function (): React.ReactElement {
  const urlParams = new URLSearchParams(window.location.search);
  const currentGameID = urlParams.get("gameID");
  // Diplomacy Lab opens the board with lab=1. The board is the whole application there, so the
  // Lab's own controls appear on it and a click can edit the position as well as order a unit.
  const isLab = urlParams.get("lab") === "1";
  const dispatch = useAppDispatch();
  React.useEffect(() => {
    dispatch(loadGame(String(currentGameID)));
  }, [dispatch, currentGameID]);
  // A branch that was just started says so on the way in, because starting one sends the browser
  // to that branch's own board.
  const newBranch = urlParams.get("newBranch");
  React.useEffect(() => {
    dispatch(gameApiSliceActions.labSetEnabled(isLab));
    // The analysis this board belongs to lives on the server, so the navigation bar is drawn from
    // what it says rather than from anything the browser has kept.
    if (isLab && currentGameID)
      dispatch(labLoadTree({ gameID: String(currentGameID) }));
    if (isLab && newBranch)
      dispatch(
        gameApiSliceActions.labSetNotice(`New branch created: ${newBranch}`),
      );
  }, [dispatch, isLab, currentGameID, newBranch]);
  const adPlacement = useAdPlacement();
  return (
    <div className="App">
      {/* The following line prevents the UI from being scaled down when the viewport is small.
      That leads to a very bad experience for this UI, with part of the map cut off. */}
      <meta name="viewport" content="width=device-width, user-scalable=no" />
      <WDAds placement={adPlacement} />
      {/* The game area is a positioned box inset by the dedicated ad areas,
      so the absolutely-positioned UI overlays anchor to it (not the window)
      and stay clear of the ads. useViewport subtracts the same insets. */}
      <div
        style={{
          position: "fixed",
          top: adPlacement.insets.top,
          left: adPlacement.insets.left,
          right: adPlacement.insets.right,
          bottom: 0,
          overflow: "hidden",
        }}
      >
        <WDMain />
      </div>
    </div>
  );
};

export default App;
