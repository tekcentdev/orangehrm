#!/bin/bash

set -euo pipefail
IFS=$'\n\t'

# === Setup Logging ===
OHRM_ENV="${OHRM_ENV:-prod}"  # Default to prod
BASE_DIR="/opt/backups/orangehrm/prod/backups"
LOG_FILE="${BASE_DIR}/restore.log"
mkdir -p "$(dirname "$LOG_FILE")"
exec > >(tee -a "$LOG_FILE") 2>&1

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
ENV_FILE="/var/www/html/orangehrm/$OHRM_ENV/shared/.env"
echo "[INFO] Using environment: $OHRM_ENV"
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

# === SETUP PATHS ===
ENCRYPTED_DIR=$(dirname "$ENCRYPTED_FILE")
BASENAME=$(basename "$ENCRYPTED_FILE" .tar.gz.enc)
TMP_DIR="${ENCRYPTED_DIR}/${BASENAME}_restore"
DECRYPTED_TAR="${ENCRYPTED_DIR}/${BASENAME}.tar.gz"

echo "[INFO] Encrypted file:     $ENCRYPTED_FILE"
echo "[INFO] Decrypted archive:  $DECRYPTED_TAR"
echo "[INFO] Extraction target:  $TMP_DIR"

# === CHECK FOR EXISTING RESTORE FOLDER ===
if [ -d "$TMP_DIR" ]; then
  echo "⚠️  Restore directory already exists: $TMP_DIR"
  echo "Please delete or rename it to proceed."
  exit 3
fi

# === DRY RUN SUPPORT ===
if [[ "${DRY_RUN:-false}" == "true" ]]; then
  echo "[DRY_RUN] Skipping decryption and extraction."
  echo "[DRY_RUN] Would decrypt: $ENCRYPTED_FILE to $DECRYPTED_TAR"
  echo "[DRY_RUN] Would extract to: $TMP_DIR"
  exit 0
fi

# === DECRYPT ===
echo "🔐 Decrypting backup..."
if ! openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 -salt \
  -in "$ENCRYPTED_FILE" \
  -out "$DECRYPTED_TAR" \
  -pass pass:"$ENCRYPTION_PASS"; then
  echo "❌ Decryption failed!"
  exit 4
fi
echo "✅ Decryption complete."

# === EXTRACT ===
echo "📦 Extracting to $TMP_DIR..."
mkdir -p "$TMP_DIR"
tar -xzf "$DECRYPTED_TAR" -C "$TMP_DIR"
chmod 700 "$TMP_DIR"
echo "✅ Extraction complete."

# === RESULTS ===
SQL_FILE=$(find "$TMP_DIR" -name "db_backup_${OHRM_ENV}_*.sql" -print -quit || true)
WEB_FILE=$(find "$TMP_DIR" -name "web_backup_${OHRM_ENV}_*.tar.gz" -print -quit || true)

echo
echo "🗂️  Extracted contents:"
ls -lh "$TMP_DIR"
echo

if [[ -z "$SQL_FILE" || ! -f "$SQL_FILE" ]]; then
  echo "⚠️  No SQL file found in restore directory!"
else
  echo "➡️  Database dump: $SQL_FILE"
fi

if [[ -z "$WEB_FILE" || ! -f "$WEB_FILE" ]]; then
  echo "⚠️  No web archive found in restore directory!"
else
  echo "➡️  Web
