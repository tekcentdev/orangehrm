#!/bin/bash

set -euo pipefail
IFS=$'\n\t'

echo "=== OrangeHRM Backup Started: $(date) ==="

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
BACKUP_DIR="/opt/backups/orangehrm-prod"

echo "Using backup directory: $BACKUP_DIR"
mkdir -p "$BACKUP_DIR"
echo "Backup directory created or already exists."

# === Backup database ===
echo "Backing up database..."
mysqldump -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$BACKUP_DIR/db_backup_$TIMESTAMP.sql"
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

# === Upload via SFTP (optional) ===
echo "Uploading encrypted archive via SFTP..."
sftp -i "$SFTP_KEY" -P "$SFTP_PORT" "$SFTP_USER@$SFTP_HOST" <<EOF
cd $SFTP_REMOTE_DIR
put $BACKUP_DIR/orangehrm_full_backup_$TIMESTAMP.tar.gz.enc
bye
EOF
echo "SFTP upload completed."

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
