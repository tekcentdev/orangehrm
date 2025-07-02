#!/bin/bash

set -euo pipefail
IFS=$'\n\t'

echo "=== OrangeHRM Restore Started: $(date) ==="

# === INPUT VALIDATION ===
if [ $# -ne 1 ]; then
  echo "Usage: $0 <encrypted-backup-file.tar.gz.enc>"
  exit 1
fi

ENCRYPTED_FILE="$1"
if [ ! -f "$ENCRYPTED_FILE" ]; then
  echo "❌ Encrypted file not found: $ENCRYPTED_FILE"
  exit 2
fi

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

# === SETUP PATHS ===
BASENAME=$(basename "$ENCRYPTED_FILE" .tar.gz.enc)
DECRYPTED_TAR="/tmp/${BASENAME}.tar.gz"
TMP_DIR="/opt/backups/orangehrm/temp/restore_$BASENAME"

# === DECRYPT ===
echo "🔐 Decrypting backup..."
openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 \
  -in "$ENCRYPTED_FILE" \
  -out "$DECRYPTED_TAR" \
  -pass pass:"$ENCRYPTION_PASS"

echo "✅ Decryption complete."

# === EXTRACT ===
echo "📦 Extracting to $TMP_DIR..."
mkdir -p "$TMP_DIR"
tar -xzf "$DECRYPTED_TAR" -C "$TMP_DIR"
echo "✅ Extraction complete."

# === RESULTS ===
echo
echo "🗂️  Extracted contents:"
ls -lh "$TMP_DIR"
echo
echo "➡️ Database dump: $TMP_DIR/db_backup_*.sql"
echo "➡️ Web archive:   $TMP_DIR/web_backup_*.tar.gz"

# === CLEANUP TEMP FILE ===
rm -f "$DECRYPTED_TAR"
echo "🧹 Cleaned up decrypted tar: $DECRYPTED_TAR"

echo "=== Restore Script Finished: $(date) ==="