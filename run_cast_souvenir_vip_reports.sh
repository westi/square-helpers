#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

# Remove prior Cast Party / Souvenir / VIP seasonal CSVs so stale seasons are not left behind.
shopt -s nullglob
old_reports=(
  reports/cast_party_sales_season_*.csv
  reports/souvenir_program_sales_season_*.csv
  reports/vip_bag_sales_season_*.csv
)
shopt -u nullglob
if ((${#old_reports[@]})); then
  rm -f -- "${old_reports[@]}"
fi

php report_cast_party_sales.php "$@"
php report_souvenir_program_sales.php "$@"
php report_vip_bag_sales.php "$@"
