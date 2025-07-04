#!/bin/bash

LOG_FILE="/opt/backups/backup.log"
exec >> "$LOG_FILE" 2>&1

set -euo pipefail
IFS=$'\n\t'

echo "[INFO] Backup started at $(date)"

# === Load environment variables ===
# IMPORTANT: You must provide your own .env file at deployment time.
ENV_FILE="${ENV_FILE:-./.env}"  # Default to local .env for testing
if [ -f "$ENV_FILE" ]; then
    set -a
    source "$ENV_FILE"
    set +a
    echo "Loaded environment variables from $ENV_FILE"
else
    echo "Env file $ENV_FILE not found!"
    exit 1
fi

# === Configuration ===
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
WEB_DIR="/var/www/html/orangehrm/prod"
BACKUP_DIR="/opt/backups/orangehrm"

echo "Using backup directory: $BACKUP_DIR"
mkdir -p "$BACKUP_DIR"
echo "Backup directory created or already exists."

# === Backup database ===
echo "Backing up database..."
mysqldump -h "$OHRM_DB_HOST" -u "$OHRM_DB_USER" -p"$OHRM_DB_PASS" "$OHRM_DB_NAME" > "$BACKUP_DIR/db_backup_$TIMESTAMP.sql"
echo "Database backup completed."

# === Backup web directory ===
echo "Backing up web directory..."
tar -czf "$BACKUP_DIR/web_backup_$TIMESTAMP.tar.gz" -C "$WEB_DIR" .
echo "Web directory backup completed."

# === Combine into single archive ===
echo "Combining database and web backups..."
tar -czf "$BACKUP_DIR/orangehrm_full_backup_$TIMESTAMP.tar.gz" -C "$BACKUP_DIR" \
    "db_backup_$TIMESTAMP.sql" "web_backup_$TIMESTAMP.tar.gz"
echo "Combined archive created."

# === Encrypt the archive ===
echo "Encrypting backup archive..."
openssl enc -aes-256-cbc -pbkdf2 -iter 100000 -salt \
  -in "$BACKUP_DIR/orangehrm_full_backup_$TIMESTAMP.tar.gz" \
  -out "$BACKUP_DIR/orangehrm_full_backup_$TIMESTAMP.tar.gz.enc" \
  -pass pass:"$ENCRYPTION_PASS"
echo "Encryption completed."

# === Upload via LFTP ===
if [ ! -f "$BACKUP_DIR/orangehrm_full_backup_$TIMESTAMP.tar.gz.enc" ]; then
  echo "❌ Backup file not found: $BACKUP_DIR/orangehrm_full_backup_$TIMESTAMP.tar.gz.enc"
  exit 1
fi

#echo "USER: $SFTP_USER"
#echo "PASS: $SFTP_PASSWORD"
#echo "Host: ftp://$SFTP_HOST:$SFTP_PORT"

echo "📡 Connecting to FTP server $SFTP_HOST to upload backup..."
echo "📄 Uploading: $BACKUP_DIR/orangehrm_full_backup_$TIMESTAMP.tar.gz.enc"

lftp -u "$SFTP_USER","$SFTP_PASSWORD" ftp://$SFTP_HOST:$SFTP_PORT -e \
"set ftp:ssl-force true; \
 set ftp:ssl-protect-data true; \
 set ssl:verify-certificate no; \
 cd backups; \
 put $BACKUP_DIR/orangehrm_full_backup_$TIMESTAMP.tar.gz.enc; \
 bye"

if [ $? -eq 0 ]; then
  echo "✅ Upload complete."
else
  echo "❌ Upload failed!"
  exit 1
fi

# === Cleanup backup files===
echo "🧹 Cleaning up FTP backups older than 21 days..."

CUTOFF=$(date -d '21 days ago' +%Y%m%d)

# Step 1: Get remote file list
REMOTE_FILES=$(lftp -u "$SFTP_USER","$SFTP_PASSWORD" ftp://$SFTP_HOST:$SFTP_PORT -e "
set ftp:ssl-force true
set ftp:ssl-protect-data true
set ssl:verify-certificate no
cd backups
cls -1 orangehrm_full_backup_*.tar.gz.enc
bye
")

# Step 2: Parse and delete old files
for FILE in $REMOTE_FILES; do
  DATEPART=$(echo "$FILE" | sed -E 's/.*_(20[0-9]{6})_[0-9]{6}\.tar\.gz\.enc/\1/')
  if [[ "$DATEPART" < "$CUTOFF" ]]; then
    echo "🗑️  Deleting: $FILE (date $DATEPART < cutoff $CUTOFF)"
    lftp -u "$SFTP_USER","$SFTP_PASSWORD" ftp://$SFTP_HOST:$SFTP_PORT -e "
      set ftp:ssl-force true
      set ftp:ssl-protect-data true
      set ssl:verify-certificate no
      cd backups
      rm $FILE
      bye
    "
  else
    echo "✅ Keeping: $FILE (date $DATEPART >= cutoff $CUTOFF)"
  fi
done

# === Cleanup unencrypted files ===
echo "Cleaning up unencrypted files..."
rm -f "$BACKUP_DIR/db_backup_$TIMESTAMP.sql"
rm -f "$BACKUP_DIR/web_backup_$TIMESTAMP.tar.gz"
rm -f "$BACKUP_DIR/orangehrm_full_backup_$TIMESTAMP.tar.gz"
echo "Cleanup completed."

# === Set secure permissions ===
chmod 600 "$BACKUP_DIR/orangehrm_full_backup_$TIMESTAMP.tar.gz.enc"
echo "Permissions set on encrypted file."

echo "=== Backup Completed Successfully: $(date) ==="