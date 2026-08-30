#!/bin/bash
# Shared helpers for the two Diplomacy Lab apps.
#
# Everything here uses only what macOS already ships with: bash, osascript and curl. There is
# nothing to install beyond Docker Desktop.

LAB_URL="http://127.0.0.1:43080"
LAB_COMPOSE_FILE="docker-compose.local.yml"
LAB_SETTINGS_DIR="$HOME/Library/Application Support/Diplomacy Lab"
LAB_PROJECT_PATH_FILE="$LAB_SETTINGS_DIR/project-path"

# Docker Desktop installs its command line tools outside the minimal PATH that an app bundle gets,
# so look in the places it actually puts them.
export PATH="/usr/local/bin:/opt/homebrew/bin:/Applications/Docker.app/Contents/Resources/bin:$HOME/.docker/bin:$PATH"

lab_notify() {
	/usr/bin/osascript -e "display notification \"$1\" with title \"Diplomacy Lab\"" >/dev/null 2>&1 || true
}

lab_alert() {
	/usr/bin/osascript -e "display dialog \"$1\" with title \"Diplomacy Lab\" buttons {\"OK\"} default button \"OK\" with icon caution" >/dev/null 2>&1 || true
}

# Ask the user where they put the Diplomacy Lab folder, and remember the answer.
lab_ask_for_project() {
	local chosen
	chosen=$(/usr/bin/osascript <<-'APPLESCRIPT' 2>/dev/null
		try
			set theFolder to choose folder with prompt "Where did you put the Diplomacy Lab folder?"
			POSIX path of theFolder
		on error
			return ""
		end try
	APPLESCRIPT
	)

	[ -z "$chosen" ] && return 1

	chosen="${chosen%/}"

	if [ ! -f "$chosen/$LAB_COMPOSE_FILE" ]; then
		lab_alert "That folder does not look like Diplomacy Lab. Choose the folder that contains $LAB_COMPOSE_FILE."
		return 1
	fi

	mkdir -p "$LAB_SETTINGS_DIR"
	printf '%s' "$chosen" > "$LAB_PROJECT_PATH_FILE"
	printf '%s' "$chosen"
}

# Find the Diplomacy Lab folder: next to the app if it is still there, otherwise wherever the user
# said it was last time, otherwise ask.
lab_find_project() {
	local appDir candidate

	# $0 is .../Diplomacy Lab.app/Contents/MacOS/<script>; the project is three levels above the
	# .app when the app is kept in the repository's mac/ folder, and four when it is at the top.
	appDir="$(cd "$(dirname "$0")/../../.." && pwd)"

	for candidate in "$appDir" "$appDir/.."; do
		if [ -f "$candidate/$LAB_COMPOSE_FILE" ]; then
			( cd "$candidate" && pwd )
			return 0
		fi
	done

	if [ -f "$LAB_PROJECT_PATH_FILE" ]; then
		candidate="$(cat "$LAB_PROJECT_PATH_FILE")"
		if [ -f "$candidate/$LAB_COMPOSE_FILE" ]; then
			printf '%s' "$candidate"
			return 0
		fi
	fi

	lab_ask_for_project
}

# Make sure Docker Desktop is installed and running, starting it if it is not.
lab_ensure_docker() {
	if ! command -v docker >/dev/null 2>&1; then
		lab_alert "Docker Desktop does not seem to be installed.\n\nInstall it from docker.com, open it once, then try again."
		return 1
	fi

	if docker info >/dev/null 2>&1; then
		return 0
	fi

	if [ ! -d "/Applications/Docker.app" ]; then
		lab_alert "Docker Desktop does not seem to be installed.\n\nInstall it from docker.com, open it once, then try again."
		return 1
	fi

	lab_notify "Starting Docker Desktop…"
	open -a Docker >/dev/null 2>&1

	# Docker Desktop can take a while on a cold start
	local waited=0
	while [ "$waited" -lt 180 ]; do
		docker info >/dev/null 2>&1 && return 0
		sleep 3
		waited=$((waited + 3))
	done

	lab_alert "Docker Desktop did not finish starting.\n\nOpen Docker Desktop yourself, wait for it to say it is running, then try again."
	return 1
}
