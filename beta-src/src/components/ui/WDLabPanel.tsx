import * as React from "react";
import {
  Box,
  Button,
  ButtonGroup,
  MenuItem,
  Select,
  Typography,
  useTheme,
} from "@mui/material";
import {
  gameLab,
  gameOverview,
  gameApiSliceActions,
  labStep,
  labSelectBranch,
  labRenameBranch,
  labDeleteBranch,
} from "../../state/game/game-api-slice";
import { useAppDispatch, useAppSelector } from "../../state/hooks";
import { LabTool, LabUnitType } from "../../state/interfaces/LabState";

/**
 * Diplomacy Lab's controls, on the board itself.
 *
 * The board is the whole application here, so everything the Lab does is reachable without leaving
 * it: build a position, order every power, adjudicate it with Ready, and walk back through what
 * has been played to try something else.
 *
 * EDIT POSITION and ORDERS are the two things a click can mean. In ORDERS the board behaves exactly
 * as webDiplomacy's board always has. In EDIT a click puts the selected power's unit on the
 * province, or takes what is there away.
 *
 * The rest is the analysis: which branch is being shown, and where along it. Stepping back and
 * adjudicating a different continuation starts a new branch by itself, so nothing that has been
 * played is ever overwritten and there is no gameID for anyone to keep track of.
 */
const WDLabPanel: React.FC = function (): React.ReactElement | null {
  const theme = useTheme();
  const dispatch = useAppDispatch();
  const lab = useAppSelector(gameLab);
  const overview = useAppSelector(gameOverview);

  const [collapsed, setCollapsed] = React.useState(false);

  // A new branch is worth mentioning, but not worth interrupting anyone over: it says so for long
  // enough to be read on the way past, and then goes.
  React.useEffect(() => {
    if (!lab.notice) return undefined;
    const timer = window.setTimeout(
      () => dispatch(gameApiSliceActions.labClearNotice()),
      12000,
    );
    return () => window.clearTimeout(timer);
  }, [lab.notice]);

  if (!lab.enabled) return null;

  const gameID = String(overview.gameID);
  const busy = lab.busy !== null;
  const editing = lab.mode === "edit";
  const { place, branches, canEdit } = lab;

  const branch = branches.find((b) => b.id === place?.branchID);
  const nodes = branch ? branch.nodes : [];
  const nodeIdx = nodes.findIndex((n) => n.id === place?.nodeID);
  const here = nodeIdx >= 0 ? nodes[nodeIdx] : null;
  const hasPrevious = nodeIdx > 0;
  const hasNext = nodeIdx >= 0 && nodeIdx < nodes.length - 1;

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
    maxWidth: "min(96vw, 940px)",
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

  /** Why the editor is or is not offered, in a sentence. */
  let editHint = "Place and remove units, and set who owns each supply centre";
  if (!canEdit)
    editHint =
      overview.phase === "Diplomacy"
        ? "This position has already been played from. Ready to try a different continuation, or step forward to the end of this branch."
        : `A ${overview.phase.toLowerCase()} phase follows from the moves before it, so the position cannot be edited here.`;

  const openBranch = (branchID: number) => {
    dispatch(labSelectBranch({ branchID: String(branchID) })).then((action) => {
      const newGameID = (action.payload as { gameID?: number })?.gameID;
      // lab=1 has to come along, or the branch opens as an ordinary board with none of the
      // Lab's controls.
      if (newGameID) window.location.search = `?gameID=${newGameID}&lab=1`;
    });
  };

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
            title={editHint}
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

        {place && (
          <>
            <Select
              data-testid="lab-branch"
              size="small"
              disabled={busy}
              value={place.branchID}
              onChange={(e) => openBranch(Number(e.target.value))}
              sx={{ minWidth: 96, height: 30, background: "#fff" }}
              title="The line of play being shown"
            >
              {branches.map((b) => (
                <MenuItem
                  key={b.id}
                  value={b.id}
                  data-testid={`lab-branch-${b.id}`}
                >
                  {b.name}
                </MenuItem>
              ))}
            </Select>

            <ButtonGroup size="small" variant="outlined" disabled={busy}>
              <Button
                data-testid="lab-previous"
                disabled={busy || !hasPrevious}
                onClick={() =>
                  dispatch(labStep({ gameID, direction: "previous" }))
                }
                title="The position before this one on this branch"
              >
                ← Previous
              </Button>
              <Button
                data-testid="lab-next"
                disabled={busy || !hasNext}
                onClick={() => dispatch(labStep({ gameID, direction: "next" }))}
                title="The position after this one on this branch"
              >
                Next →
              </Button>
            </ButtonGroup>

            <Typography
              data-testid="lab-position-label"
              variant="caption"
              sx={{ color: "#333", whiteSpace: "nowrap" }}
            >
              {here ? here.label : ""}
              {nodes.length > 1 ? ` (${nodeIdx + 1}/${nodes.length})` : ""}
            </Typography>

            <Button
              data-testid="lab-rename"
              size="small"
              variant="outlined"
              disabled={busy}
              onClick={() => {
                const name = window.prompt(
                  "Name for this branch",
                  place.branchName,
                );
                if (name === null) return;
                dispatch(
                  labRenameBranch({
                    gameID,
                    branchID: String(place.branchID),
                    name,
                  }),
                );
              }}
              title="Rename this branch"
            >
              Rename
            </Button>

            <Button
              data-testid="lab-delete-branch"
              size="small"
              variant="outlined"
              disabled={busy || branches.length < 2}
              onClick={() => {
                const other = branches.find((b) => b.id !== place.branchID);
                if (!other) return;
                const name = window.prompt(
                  `Delete which branch? (${branches
                    .filter((b) => b.id !== place.branchID)
                    .map((b) => b.name)
                    .join(", ")})`,
                  other.name,
                );
                if (name === null) return;
                const target = branches.find((b) => b.name === name.trim());
                if (!target) {
                  window.alert(`There is no branch called "${name}".`);
                  return;
                }
                dispatch(
                  labDeleteBranch({ gameID, branchID: String(target.id) }),
                );
              }}
              title="Delete a branch other than this one"
            >
              Delete branch
            </Button>
          </>
        )}

        <Button
          data-testid="lab-new"
          size="small"
          variant="outlined"
          disabled={busy}
          onClick={() => {
            window.location.href = "../lab.php?newBoard=1";
          }}
          title="Start a new scenario on an empty board"
        >
          New scenario
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
          : "Enter orders for every power, then press Ready to adjudicate. Step back and adjudicate something else, and a new branch is started for it."}
      </Typography>

      {lab.notice && (
        <Typography
          data-testid="lab-notice"
          variant="caption"
          sx={{ display: "block", mt: 0.5, color: "#060", fontWeight: 600 }}
        >
          {lab.notice}
        </Typography>
      )}

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
