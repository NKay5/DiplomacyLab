enum ApiRoute {
  // fetch
  GAME_OVERVIEW = "game/overview",
  GAME_DATA = "game/data",
  GAME_STATUS = "game/status",
  GAME_MESSAGES = "game/getmessages",
  PLAYERS_ACTIVE_GAMES = "players/active_games",
  // post
  SEND_MESSAGE = "game/sendmessage",
  MESSAGES_SEEN = "game/messagesseen",
  GAME_SETVOTE = "game/setvote",
  SET_BACK_FROM_LEFT = "game/markbackfromleft",
  SSE_AUTHENTICATION = "sse/authentication",
  PUSH_CONFIG = "push/config",
  PUSH_SUBSCRIBE = "push/subscribe",
  // get sandbox
  SANDBOX_COPY = "sandbox/copy",
  SANDBOX_MOVETURNBACK = "sandbox/moveTurnBack",
  SANDBOX_DELETE = "sandbox/delete",
  // Diplomacy Lab: the board is the application, so it drives the position itself
  LAB_EDIT_PROVINCE = "lab/editProvince",
  LAB_TREE = "lab/tree",
  LAB_READY = "lab/ready",
  LAB_STEP = "lab/step",
  LAB_SELECT_BRANCH = "lab/branch/select",
  LAB_SELECT_NODE = "lab/node/select",
  LAB_RENAME_BRANCH = "lab/branch/rename",
  LAB_DELETE_BRANCH = "lab/branch/delete",
}

export default ApiRoute;
