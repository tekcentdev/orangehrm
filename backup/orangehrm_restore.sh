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

# === Determine environment ===
OHRM_ENV="${OHRM_ENV:-prod}"  # Default to 'prod' if not set
ENV_FILE="/var/www/html/orangehrm/$OHRM_ENV/shared/.env"

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

# === SETUP PATHS ===
ENCRYPTED_DIR=$(dirname "$ENCRYPTED_FILE")
BASENAME=$(basename "$ENCRYPTED_FILE" .tar.gz.enc)
DECRYPTED_TAR="${ENCRYPTED_DIR}/${BASENAME}.tar.gz"
TMP_DIR="${ENCRYPTED_DIR}/${BASENAME}_restore"

echo "📄 Encrypted file: $ENCRYPTED_FILE"
echo "📦 Decrypted tar will be: $DECRYPTED_TAR"
echo "📂 Extraction target directory: $TMP_DIR"

# === CHECK FOR EXISTING RESTORE FOLDER ===
if [ -d "$TMP_DIR" ]; then
  echo "⚠️ Restore directory already exists: $TMP_DIR"
  echo "Please delete it or rename the encrypted file to avoid conflict."
  exit 3
fi

# === DRY RUN SUPPORT (optional override) ===
if [[ "${DRY_RUN:-false}" == "true" ]]; then
  echo "🧪 Dry run mode enabled — skipping decryption and extraction."
  exit 0
fi

# === DECRYPT ===
echo "🔐 Decrypting backup..."
openssl enc -d -aes-256-cbc -pbkdf2 -iter 100000 -salt \
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
SQL_FILE=$(find "$TMP_DIR" -name "db_backup_*.sql" | head -n1 || true)
WEB_FILE=$(find "$TMP_DIR" -name "web_backup_*.tar.gz" | head -n1 || true)

echo
echo "🗂️  Extracted contents:"
ls -lh "$TMP_DIR"
echo
echo "➡️ Database dump: ${SQL_FILE:-Not found}"
echo "➡️ Web archive:   ${WEB_FILE:-Not found}"

# === CLEANUP TEMP FILE ===
rm -f "$DECRYPTED_TAR"
echo "🧹 Cleaned up decrypted tar: $DECRYPTED_TAR"

echo "=== Restore Script Finished: $(date) ==="
