import * as React from "react";
import { Box, Button, ButtonGroup, Typography, useTheme } from "@mui/material";
import {
  gameLab,
  gameOverview,
  gameApiSliceActions,
  labResolve,
  labReset,
  labDuplicate,
  labSavePosition,
} from "../../state/game/game-api-slice";
import { useAppDispatch, useAppSelector } from "../../state/hooks";
import { LabTool, LabUnitType } from "../../state/interfaces/LabState";

/**
 * Diplomacy Lab's controls, on the board itself.
 *
 * The board is the whole application here, so everything the Lab does is reachable without leaving
 * it: build a position, order every power, adjudicate, and step back to try something else.
 *
 * EDIT POSITION and ORDERS are the two things a click can mean. In ORDERS the board behaves exactly
 * as webDiplomacy's board always has. In EDIT a click puts the selected power's unit on the
 * province, or takes what is there away.
 */
const WDLabPanel: React.FC = function (): React.ReactElement | null {
  const theme = useTheme();
  const dispatch = useAppDispatch();
  const lab = useAppSelector(gameLab);
  const overview = useAppSelector(gameOverview);

  const [collapsed, setCollapsed] = React.useState(false);

  if (!lab.enabled) return null;

  const gameID = String(overview.gameID);
  const busy = lab.busy !== null;
  const editing = lab.mode === "edit";

  // A position is something you set up; a retreat or an adjustment is something that happened.
  // Both carry state that only means anything as the outcome of the phase before - which unit was
  // dislodged, what may be built and where - so the board is only editable on a Movement phase.
  const canEdit = overview.phase === "Diplomacy";

  /** Every power, plus "Neutral", which clears a province or leaves a centre unowned. */
  const countries: { id: number; label: string; colour: string }[] = [
    { id: 0, label: "Neutral", colour: theme.palette.grey[500] },
    ...overview.members
      .slice()
      .sort((a, b) => a.countryID - b.countryID)
      .map((m) => ({
        id: m.countryID,
        label: m.country,
        colour: theme.palette[m.country]?.main ?? theme.palette.grey[500],
      })),
  ];

  const toolLabels: { tool: LabTool; label: string; hint: string }[] = [
    {
      tool: "unit",
      label: "Unit",
      hint: "Click a province to place a unit there",
    },
    {
      tool: "center",
      label: "Centre",
      hint: "Click a supply centre to change who owns it",
    },
    { tool: "erase", label: "Erase", hint: "Click a province to empty it" },
  ];

  const unitTypes: LabUnitType[] = ["Auto", "Army", "Fleet"];

  const panel: React.CSSProperties = {
    position: "absolute",
    top: 8,
    left: "50%",
    transform: "translateX(-50%)",
    zIndex: 100,
    background: "rgba(255,255,255,0.96)",
    borderRadius: 8,
    boxShadow: "0 2px 10px rgba(0,0,0,0.25)",
    padding: "8px 12px",
    maxWidth: "min(96vw, 900px)",
  };

  if (collapsed) {
    return (
      <Box sx={panel}>
        <Button size="small" onClick={() => setCollapsed(false)}>
          Diplomacy Lab
        </Button>
      </Box>
    );
  }

  return (
    <Box sx={panel} data-testid="lab-panel">
      <Box
        sx={{ display: "flex", flexWrap: "wrap", gap: 1, alignItems: "center" }}
      >
        <ButtonGroup size="small" variant="contained" disabled={busy}>
          <Button
            data-testid="lab-mode-edit"
            color={editing ? "primary" : "inherit"}
            disabled={busy || !canEdit}
            title={
              canEdit
                ? "Place and remove units, and set who owns each supply centre"
                : `A ${overview.phase.toLowerCase()} phase follows from the moves before it, so the position cannot be edited here. Resolve it, or Reset.`
            }
            onClick={() => dispatch(gameApiSliceActions.labSetMode("edit"))}
          >
            Edit position
          </Button>
          <Button
            data-testid="lab-mode-orders"
            color={!editing ? "primary" : "inherit"}
            onClick={() => dispatch(gameApiSliceActions.labSetMode("orders"))}
          >
            Orders
          </Button>
        </ButtonGroup>

        <Button
          data-testid="lab-resolve"
          size="small"
          variant="contained"
          color="success"
          disabled={busy}
          onClick={() => dispatch(labResolve({ gameID }))}
        >
          {lab.busy === "resolve" ? "Resolving…" : "Resolve"}
        </Button>

        <Button
          data-testid="lab-reset"
          size="small"
          variant="outlined"
          disabled={busy}
          onClick={() => dispatch(labReset({ gameID }))}
          title="Go back to the position as it was before the last adjudication"
        >
          Reset
        </Button>

        <Button
          data-testid="lab-duplicate"
          size="small"
          variant="outlined"
          disabled={busy}
          onClick={() => {
            const name = window.prompt(
              "Name for the copy",
              `${overview.name || "Position"} (variation)`,
            );
            if (name === null) return;
            dispatch(labDuplicate({ gameID, name })).then((action) => {
              const newGameID = (action.payload as { gameID?: number })?.gameID;
              // lab=1 has to come along, or the copy opens as an ordinary board with none of
              // the Lab's controls.
              if (newGameID)
                window.location.search = `?gameID=${newGameID}&lab=1`;
            });
          }}
          title="Make an independent copy of this position to try a different line"
        >
          Duplicate
        </Button>

        <Button
          data-testid="lab-save"
          size="small"
          variant="outlined"
          disabled={busy}
          onClick={() => {
            const name = window.prompt("Save this position as", "");
            if (name === null) return;
            dispatch(labSavePosition({ gameID, name }));
          }}
        >
          Save
        </Button>

        <Button
          data-testid="lab-new"
          size="small"
          variant="outlined"
          disabled={busy}
          onClick={() => {
            window.location.href = "../lab.php?newBoard=1";
          }}
          title="Start again from an empty board"
        >
          New
        </Button>

        <Button size="small" onClick={() => setCollapsed(true)}>
          Hide
        </Button>
      </Box>

      {editing && (
        <Box
          sx={{
            mt: 1,
            display: "flex",
            flexWrap: "wrap",
            gap: 1,
            alignItems: "center",
          }}
        >
          <ButtonGroup size="small" disabled={busy}>
            {toolLabels.map(({ tool, label, hint }) => (
              <Button
                key={tool}
                data-testid={`lab-tool-${tool}`}
                title={hint}
                variant={lab.tool === tool ? "contained" : "outlined"}
                onClick={() => dispatch(gameApiSliceActions.labSetTool(tool))}
              >
                {label}
              </Button>
            ))}
          </ButtonGroup>

          {lab.tool === "unit" && (
            <ButtonGroup size="small" disabled={busy}>
              {unitTypes.map((type) => (
                <Button
                  key={type}
                  data-testid={`lab-unittype-${type}`}
                  variant={lab.unitType === type ? "contained" : "outlined"}
                  onClick={() =>
                    dispatch(gameApiSliceActions.labSetUnitType(type))
                  }
                  title={
                    type === "Auto"
                      ? "Army on land, fleet at sea; click a coast again to turn its army into a fleet"
                      : `Always place a ${type.toLowerCase()}`
                  }
                >
                  {type}
                </Button>
              ))}
            </ButtonGroup>
          )}

          <Box sx={{ display: "flex", flexWrap: "wrap", gap: 0.5 }}>
            {countries.map(({ id, label, colour }) => (
              <Button
                key={id}
                data-testid={`lab-country-${id}`}
                size="small"
                disabled={busy}
                onClick={() => dispatch(gameApiSliceActions.labSetCountry(id))}
                sx={{
                  minWidth: 0,
                  padding: "2px 8px",
                  color: "#000",
                  background: colour,
                  border:
                    lab.countryID === id
                      ? "2px solid #000"
                      : "2px solid transparent",
                  fontWeight: lab.countryID === id ? 700 : 400,
                  "&:hover": { background: colour, opacity: 0.85 },
                }}
              >
                {label}
              </Button>
            ))}
          </Box>
        </Box>
      )}

      <Typography
        variant="caption"
        sx={{ display: "block", mt: 0.5, color: "#555" }}
      >
        {editing
          ? "Click a province to change what is on it. Nothing is enforced except the map itself: powers may have no units, and unit counts need not match centres."
          : "Enter orders for every power, then press Resolve. Reset returns to the position from before the last adjudication."}
      </Typography>

      {lab.error && (
        <Typography
          data-testid="lab-error"
          variant="caption"
          sx={{ display: "block", mt: 0.5, color: "#b00" }}
          onClick={() => dispatch(gameApiSliceActions.labClearError())}
        >
          {lab.error}
        </Typography>
      )}
    </Box>
  );
};

export default WDLabPanel;
