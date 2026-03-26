#!/bin/bash

set -euo pipefail
IFS=$'\n\t'

# === Determine environment ===
OHRM_ENV="${OHRM_ENV:-prod}"  # Default to 'prod' if not set

# === Set paths ===
BACKUP_ROOT="/opt/backups/orangehrm/${OHRM_ENV}"
BACKUP_DIR="${BACKUP_ROOT}/backups"
LOG_FILE="${BACKUP_ROOT}/backup.log"

# Ensure directories exist
mkdir -p "$BACKUP_DIR"
mkdir -p "$(dirname "$LOG_FILE")"

# Redirect all output to log file
exec > >(tee -a "$LOG_FILE") 2>&1

echo "[INFO] Backup started at $(date)"
echo "[INFO] Environment: $OHRM_ENV"
echo "[INFO] Backup directory: $BACKUP_DIR"
echo "[INFO] Log file: $LOG_FILE"

# === Load environment variables ===
ENV_FILE="/var/www/html/orangehrm/${OHRM_ENV}/shared/.env"
echo "[INFO] Loading environment variables from: $ENV_FILE"

if [ -f "$ENV_FILE" ]; then
    set -a
    source "$ENV_FILE"
    set +a
    echo "[INFO] Environment variables loaded."
else
    echo "[ERROR] Env file $ENV_FILE not found!"
    exit 1
fi

# === DRY RUN SUPPORT ===
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
ENC_FILE="${BACKUP_DIR}/orangehrm_full_backup_${OHRM_ENV}_${TIMESTAMP}.tar.gz.enc"

if [[ "${DRY_RUN:-false}" == "true" ]]; then
  echo "[DRY_RUN] Skipping backup, encryption, upload, and cleanup."
  echo "[DRY_RUN] Would create: $ENC_FILE"
  exit 0
fi

# === Create MySQL dump ===
DB_DUMP_FILE="${BACKUP_DIR}/db_backup_${OHRM_ENV}_${TIMESTAMP}.sql"
echo "[INFO] Dumping database to: $DB_DUMP_FILE"
mysqldump --single-transaction --skip-lock-tables -h "$OHRM_DB_HOST" -u "$OHRM_DB_USER" -p"$OHRM_DB_PASS" "$OHRM_DB_NAME" > "$DB_DUMP_FILE"
echo "[INFO] Database dump complete."

# === Archive web files ===
WEB_DIR="/var/www/html/orangehrm/${OHRM_ENV}"
WEB_ARCHIVE_FILE="${BACKUP_DIR}/web_backup_${OHRM_ENV}_${TIMESTAMP}.tar.gz"
echo "[INFO] Archiving web directory: $WEB_DIR"
tar -czf "$WEB_ARCHIVE_FILE" -C "$WEB_DIR" .
echo "[INFO] Web archive created."

# === Combine into full backup ===
COMBINED_TAR="${BACKUP_DIR}/orangehrm_full_backup_${OHRM_ENV}_${TIMESTAMP}.tar.gz"
echo "[INFO] Creating combined archive: $COMBINED_TAR"
tar -czf "$COMBINED_TAR" -C "$BACKUP_DIR" "$(basename "$DB_DUMP_FILE")" "$(basename "$WEB_ARCHIVE_FILE")"
echo "[INFO] Combined archive ready."

# === Encrypt the archive ===
echo "[INFO] Encrypting combined archive..."
openssl enc -aes-256-cbc -pbkdf2 -iter 100000 -salt \
  -in "$COMBINED_TAR" \
  -out "$ENC_FILE" \
  -pass pass:"$ENCRYPTION_PASS"
echo "[INFO] Encrypted file: $ENC_FILE"

# === Upload to FTP ===
echo "[INFO] Uploading encrypted backup to FTP..."
lftp -u "$SFTP_USER","$SFTP_PASSWORD" ftp://$SFTP_HOST:$SFTP_PORT -e "
  set ftp:ssl-force true
  set ftp:ssl-protect-data true
  set ssl:verify-certificate no
  cd backups
  put $ENC_FILE
  bye
"
echo "[INFO] FTP upload complete."

# === Cleanup remote backups older than 21 days ===
CUTOFF=$(date -d '21 days ago' +%Y%m%d)
echo "[INFO] Cleaning up FTP files older than $CUTOFF..."

REMOTE_FILES=$(lftp -u "$SFTP_USER","$SFTP_PASSWORD" ftp://$SFTP_HOST:$SFTP_PORT -e "
  set ftp:ssl-force true
  set ftp:ssl-protect-data true
  set ssl:verify-certificate no
  cd backups
  cls -1 orangehrm_full_backup_${OHRM_ENV}_*.tar.gz.enc
  bye
")

for FILE in $REMOTE_FILES; do
  DATEPART=$(echo "$FILE" | sed -E 's/.*_(20[0-9]{6})_[0-9]{6}\.tar\.gz\.enc/\1/')
  if [[ "$DATEPART" < "$CUTOFF" ]]; then
    echo "[INFO] Deleting remote file: $FILE"
    lftp -u "$SFTP_USER","$SFTP_PASSWORD" ftp://$SFTP_HOST:$SFTP_PORT -e "
      set ftp:ssl-force true
      set ftp:ssl-protect-data true
      set ssl:verify-certificate no
      cd backups
      rm $FILE
      bye
    "
  fi
done

# === Cleanup local temporary files ===
echo "[INFO] Cleaning up local temporary files..."
rm -f "$DB_DUMP_FILE" "$WEB_ARCHIVE_FILE" "$COMBINED_TAR"

# === Secure permissions ===
chmod 600 "$ENC_FILE"
echo "[INFO] Backup completed successfully at $(date)"
