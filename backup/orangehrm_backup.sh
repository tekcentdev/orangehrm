#!/bin/bash

LOG_FILE="/opt/backups/backup.log"
exec >> "$LOG_FILE" 2>&1

set -euo pipefail
IFS=$'\n\t'

echo "[INFO] Backup started at $(date)"

# === Determine environment ===
OHRM_ENV="${OHRM_ENV:-prod}"  # Default to 'prod' if not set
ENV_FILE="/var/www/html/orange/$OHRM_ENV/shared/.env"

echo "[INFO] Using environment: $OHRM_ENV"
echo "[INFO] Loading environment variables from: $ENV_FILE"

# === Load environment variables ===
if [ -f "$ENV_FILE" ]; then
    set -a
    source "$ENV_FILE"
    set +a
    echo "[INFO] Environment variables loaded from $ENV_FILE"
else
    echo "[ERROR] Env file $ENV_FILE not found!"
    exit 1
fi

# === Configuration ===
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
WEB_DIR="/var/www/html/orangehrm/$OHRM_ENV"
BACKUP_DIR="/opt/backups/orangehrm/$OHRM_ENV"

echo "[INFO] Web directory: $WEB_DIR"
echo "[INFO] Backup directory: $BACKUP_DIR"

# === DRY RUN SUPPORT ===
if [[ "${DRY_RUN:-false}" == "true" ]]; then
  echo "[DRY_RUN] DRY_RUN mode enabled. Skipping backup, encryption, upload, and cleanup."
  echo "[DRY_RUN] Would back up DB:     $OHRM_DB_NAME on $OHRM_DB_HOST"
  echo "[DRY_RUN] Would back up WEB:    $WEB_DIR"
  echo "[DRY_RUN] Would write to DIR:   $BACKUP_DIR"
  echo "[DRY_RUN] Would create archive: $BACKUP_DIR/orangehrm_full_backup_$TIMESTAMP.tar.gz"
  echo "[DRY_RUN] Would encrypt using:  ENCRYPTION_PASS"
  echo "[DRY_RUN] Would upload to FTP:  ftp://$SFTP_HOST:$SFTP_PORT/backups/"
  exit 0
fi

# === Create backup directory ===
mkdir -p "$BACKUP_DIR"
echo "[INFO] Backup directory created or already exists."

# === Backup database ===
echo "[INFO] Backing up database..."
mysqldump -h "$OHRM_DB_HOST" -u "$OHRM_DB_USER" -p"$OHRM_DB_PASS" "$OHRM_DB_NAME" > "$BACKUP_DIR/db_backup_$TIMESTAMP.sql"
echo "[INFO] Database backup completed."

# === Backup web directory ===
echo "[INFO] Backing up web directory..."
tar -czf "$BACKUP_DIR/web_backup_$TIMESTAMP.tar.gz" -C "$WEB_DIR" .
echo "[INFO] Web directory backup completed."

# === Combine into single archive ===
echo "[INFO] Combining database and web backups..."
tar -czf "$BACKUP_DIR/orangehrm_full_backup_$TIMESTAMP.tar.gz" -C "$BACKUP_DIR" \
    "db_backup_$TIMESTAMP.sql" "web_backup_$TIMESTAMP.tar.gz"
echo "[INFO] Combined archive created."

# === Encrypt the archive ===
echo "[INFO] Encrypting backup archive..."
openssl enc -aes-256-cbc -pbkdf2 -iter 100000 -salt \
  -in "$BACKUP_DIR/orangehrm_full_backup_$TIMESTAMP.tar.gz" \
  -out "$BACKUP_DIR/orangehrm_full_backup_$TIMESTAMP.tar.gz.enc" \
  -pass pass:"$ENCRYPTION_PASS"
echo "[INFO] Encryption completed."

# === Upload via LFTP ===
ENC_FILE="$BACKUP_DIR/orangehrm_full_backup_$TIMESTAMP.tar.gz.enc"
if [ ! -f "$ENC_FILE" ]; then
  echo "[ERROR] Encrypted backup file not found: $ENC_FILE"
  exit 1
fi

echo "[INFO] Uploading encrypted backup to FTP server..."
lftp -u "$SFTP_USER","$SFTP_PASSWORD" ftp://$SFTP_HOST:$SFTP_PORT -e "
  set ftp:ssl-force true
  set ftp:ssl-protect-data true
  set ssl:verify-certificate no
  cd backups
  put $ENC_FILE
  bye
"

if [ $? -eq 0 ]; then
  echo "[INFO] ✅ Upload complete."
else
  echo "[ERROR] ❌ Upload failed!"
  exit 1
fi

# === Cleanup FTP backups older than 21 days ===
echo "[INFO] Cleaning up FTP backups older than 21 days..."
CUTOFF=$(date -d '21 days ago' +%Y%m%d)

REMOTE_FILES=$(lftp -u "$SFTP_USER","$SFTP_PASSWORD" ftp://$SFTP_HOST:$SFTP_PORT -e "
  set ftp:ssl-force true
  set ftp:ssl-protect-data true
  set ssl:verify-certificate no
  cd backups
  cls -1 orangehrm_full_backup_*.tar.gz.enc
  bye
")

for FILE in $REMOTE_FILES; do
  DATEPART=$(echo "$FILE" | sed -E 's/.*_(20[0-9]{6})_[0-9]{6}\.tar\.gz\.enc/\1/')
  if [[ "$DATEPART" < "$CUTOFF" ]]; then
    echo "[INFO] 🗑️  Deleting: $FILE (date $DATEPART < cutoff $CUTOFF)"
    lftp -u "$SFTP_USER","$SFTP_PASSWORD" ftp://$SFTP_HOST:$SFTP_PORT -e "
      set ftp:ssl-force true
      set ftp:ssl-protect-data true
      set ssl:verify-certificate no
      cd backups
      rm $FILE
      bye
    "
  else
    echo "[INFO] ✅ Keeping: $FILE (date $DATEPART >= cutoff $CUTOFF)"
  fi
done

# === Cleanup local unencrypted files ===
echo "[INFO] Cleaning up temporary local files..."
rm -f "$BACKUP_DIR/db_backup_$TIMESTAMP.sql"
rm -f "$BACKUP_DIR/web_backup_$TIMESTAMP.tar.gz"
rm -f "$BACKUP_DIR/orangehrm_full_backup_$TIMESTAMP.tar.gz"
echo "[INFO] Local cleanup complete."

# === Secure the final encrypted archive ===
chmod 600 "$ENC_FILE"
echo "[INFO] Permissions set on encrypted backup file."

echo "=== Backup Completed Successfully: $(date) ==="
