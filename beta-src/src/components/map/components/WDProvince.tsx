import { useTheme } from "@mui/material";
import * as React from "react";
import countryMap from "../../../data/map/variants/classic/CountryMap";
import { ProvinceMapData } from "../../../interfaces";
import {
  gameApiSliceActions,
  gameLab,
  gameMaps,
  gameOverview,
  labEditProvince,
} from "../../../state/game/game-api-slice";
import { useAppDispatch, useAppSelector } from "../../../state/hooks";
import ClickObjectType from "../../../types/state/ClickObjectType";
import WDCenter from "./WDCenter";
import WDLabel from "./WDLabel";
import Province from "../../../enums/map/variants/classic/Province";
import Territory from "../../../enums/map/variants/classic/Territory";

interface WDProvinceProps {
  provinceMapData: ProvinceMapData;
  ownerCountryID: string | undefined;
  playerCountryID: number | undefined;
  highlightSelection: boolean;
}

const WDProvince: React.FC<WDProvinceProps> = function ({
  provinceMapData,
  ownerCountryID,
  playerCountryID,
  highlightSelection,
}): React.ReactElement {
  const theme = useTheme();
  const dispatch = useAppDispatch();

  const { user, members, gameID } = useAppSelector(gameOverview);
  const maps = useAppSelector(gameMaps);
  const lab = useAppSelector(gameLab);

  const { province } = provinceMapData;
  let territoryFill = "none";
  let territoryFillOpacity = 0;
  const territoryStrokeOpacity = 1;

  // Normally, color according to supply center ownership
  if (ownerCountryID) {
    const ownerCountry = members.find(
      (m) => m.countryID === Number(ownerCountryID),
    )?.country;
    if (ownerCountry && provinceMapData.type !== "Sea") {
      territoryFill = theme.palette[ownerCountry]?.main;
      territoryFillOpacity = 0.4;
    }
  }

  // If highlighting a selection, color according to the active player's
  // color, and more opaque.
  if (highlightSelection && playerCountryID) {
    const playerCountry = members.find(
      (m) => m.countryID === playerCountryID,
    )?.country;
    if (playerCountry) {
      territoryFill = theme.palette[playerCountry]?.main;
      territoryFillOpacity = 1.0;
    }
  }

  const clickAction = function (
    evt: React.MouseEvent<SVGGElement, MouseEvent>,
  ) {
    // In Diplomacy Lab the same board is used to build a position and then to play it out. While
    // editing, a click changes what is on the province rather than starting an order; the server
    // decides what the province can actually hold.
    if (lab.enabled && lab.mode === "edit") {
      // Clicking province after province is faster than a round trip, so the clicks queue up
      // rather than being dropped or racing each other; see labRequest in the slice.
      // Any territory of this province will do: the server normalises coasts to their province.
      const terrID = Object.keys(maps.terrIDToProvince).find(
        (id) => maps.terrIDToProvince[id] === province,
      );
      if (!terrID) return;

      dispatch(
        labEditProvince({
          gameID: String(gameID),
          terrID: String(terrID),
          tool: lab.tool,
          countryID: String(lab.countryID),
          unitType: lab.unitType,
        }),
      );
      return;
    }

    dispatch(
      gameApiSliceActions.processMapClick({
        evt,
        clickProvince: province,
      }),
    );
  };
  return (
    <svg
      height={provinceMapData.height}
      id={`${province}-province`}
      viewBox={provinceMapData.viewBox}
      width={provinceMapData.width}
      x={provinceMapData.x}
      y={provinceMapData.y}
    >
      <g onClick={(e) => clickAction(e)}>
        {provinceMapData.texture?.texture && (
          <path
            d={provinceMapData.path}
            fill={provinceMapData.texture.texture}
            id={`${province}-texture`}
            stroke={provinceMapData.texture.stroke}
            strokeOpacity={provinceMapData.texture.strokeOpacity}
            strokeWidth={provinceMapData.texture.strokeWidth}
          />
        )}
        <path
          d={provinceMapData.path}
          fill={territoryFill}
          fillOpacity={territoryFillOpacity}
          id={`${province}-control-path`}
          stroke={theme.palette.primary.main}
          strokeOpacity={1}
          strokeWidth={territoryStrokeOpacity}
        />
      </g>
      {provinceMapData.centerPos && (
        <g className="no-pointer-events">
          <WDCenter
            province={province}
            x={provinceMapData.centerPos.x}
            y={provinceMapData.centerPos.y}
          />
        </g>
      )}
      {provinceMapData.labels &&
        provinceMapData.labels.map(({ name, text, style, x, y }, i) => {
          let txt = text;
          const id = `${province}-label-${name}`;
          if (!txt) {
            txt = provinceMapData.abbr;
          }
          return (
            <g key={id} className="no-pointer-events">
              <WDLabel
                id={id}
                name={name}
                key={id || i}
                style={style}
                text={txt}
                x={x}
                y={y}
              />
            </g>
          );
        })}
    </svg>
  );
};

export default WDProvince;
