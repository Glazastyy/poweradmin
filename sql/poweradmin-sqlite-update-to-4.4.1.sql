-- Poweradmin schema update to 4.4.1
-- Add WebAuthn passkey storage for MFA.

CREATE TABLE IF NOT EXISTS user_passkeys (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    credential_id TEXT NOT NULL,
    public_key TEXT NOT NULL,
    name TEXT NOT NULL,
    sign_count INTEGER NOT NULL DEFAULT 0,
    transports TEXT NULL,
    aaguid TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS idx_user_passkeys_credential_id ON user_passkeys(credential_id);
CREATE INDEX IF NOT EXISTS idx_user_passkeys_user_id ON user_passkeys(user_id);
