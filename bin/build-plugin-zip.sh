#!/bin/bash

# Exit if any command fails.
set -e

# Store paths
SOURCE_PATH=$(pwd)

# Change to the expected directory.
cd "$(dirname "$0")"
cd ..

# Enable nicer messaging for build status.
BLUE_BOLD='\033[1;34m';
GREEN_BOLD='\033[1;32m';
RED_BOLD='\033[1;31m';
YELLOW_BOLD='\033[1;33m';
COLOR_RESET='\033[0m';
error () {
	echo -e "\n${RED_BOLD}$1${COLOR_RESET}\n"
}
status () {
	echo -e "\n${BLUE_BOLD}$1${COLOR_RESET}\n"
}
success () {
	echo -e "\n${GREEN_BOLD}$1${COLOR_RESET}\n"
}
warning () {
	echo -e "\n${YELLOW_BOLD}$1${COLOR_RESET}\n"
}

copy_dest_files() {
	CURRENT_DIR=$(pwd)
	cd "$1" || exit
	rsync ./ "$2"/ --recursive --delete --delete-excluded \
		--exclude=".*/" \
		--exclude="*.md" \
		--exclude=".*" \
		--exclude="composer.*" \
		--exclude="*.lock" \
		--exclude=bin/ \
		--exclude=node_modules/ \
		--exclude=tests/ \
		--exclude=release/ \
		--exclude=phpcs.xml \
		--exclude=phpunit.xml.dist \
		--exclude=renovate.json \
		--exclude="*.config.js" \
		--exclude="*-config.js" \
		--exclude="*.config.json" \
		--exclude=package.json \
		--exclude=package-lock.json \
		--exclude=none \
		--exclude=Gruntfile.js \
		--exclude=auth.json \
		--exclude=shiptastic-for-woocommerce.zip \
		--exclude="zip-file/"
	status "Done copying files!"
	cd "$CURRENT_DIR" || exit
}

status "💃 Time to release Shiptastic 🕺"

# Run the build.
status "Installing dependencies... 📦"
composer install --no-dev

# Generate the plugin zip file.
status "Creating archive... 🎁"
mkdir zip-file
mkdir zip-file/build
copy_dest_files $SOURCE_PATH "$SOURCE_PATH/zip-file"
cd zip-file
zip -r ../shiptastic-for-woocommerce.zip ./
cd ..
rm -r zip-file

success "Done. You've built Shiptastic! 🎉"