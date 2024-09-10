#!/bin/sh
set -e

source /gh-toolkit/shell.sh

TYPE="$(gh_input "TYPE")"
SAVE_PATH="$(gh_input "SAVE_PATH")"

if [[ -z "$SAVE_PATH" ]]; then
 echo "Set File Save destination"
 exit 1
fi

if [[ -z "$TYPE" ]]; then
 echo "Set type"
 exit 1
fi

gh_log
gh_log_group_start "🔽 Downloading WP-CLI"
curl -O https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
chmod +x wp-cli.phar
mv wp-cli.phar /usr/local/bin/wp
gh_log_group_end

gh_log
gh_log_group_start "📝 Generating i18n JSON files"
if [ "${TYPE}" = "json" ]; then
  wp i18n make-json --no-purge --allow-root "$SAVE_PATH"
elif [ "${TYPE}" = "php" ]; then
  wp i18n make-php --allow-root "$SAVE_PATH"
fi
gh_log_group_end