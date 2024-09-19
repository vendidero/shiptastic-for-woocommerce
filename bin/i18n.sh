#!/bin/sh

# Output colorized strings
#
# Color codes:
# 0 - black
# 1 - red
# 2 - green
# 3 - yellow
# 4 - blue
# 5 - magenta
# 6 - cian
# 7 - white
output() {
	echo "$(tput setaf "$1")$2$(tput sgr0)"
}

wp i18n make-pot ./ ./i18n/languages/shiptastic-for-woocommerce.pot --ignore-domain --exclude="assets/,release/,node_modules/"

# Run composer update to make sure POT file paths are being updated
composer update

# Refresh po from pot
msgmerge -U --suffix=off --backup=none ./i18n/languages/shiptastic-for-woocommerce-de_DE.po ./i18n/languages/shiptastic-for-woocommerce.pot
msgmerge -U --suffix=off --backup=none ./i18n/languages/shiptastic-for-woocommerce-de_DE_formal.po ./i18n/languages/shiptastic-for-woocommerce.pot

output 2 "Done! You may now edit the merged po files"