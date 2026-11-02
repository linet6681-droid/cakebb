#!/bin/sh
# Usage: sh tools/backup.sh   (run from project folder; asks for MySQL password if set)
mysqldump -u root -p --routines cakeb > "backup_cakeb_$(date +%Y%m%d_%H%M).sql" && echo "Backup done."
