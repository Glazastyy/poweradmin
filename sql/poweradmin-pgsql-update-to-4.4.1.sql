-- Poweradmin schema update to 4.4.1
-- Add WebAuthn passkey storage for MFA.

CREATE SEQUENCE IF NOT EXISTS user_passkeys_id_seq INCREMENT 1 MINVALUE 1 MAXVALUE 2147483647 CACHE 1;

CREATE TABLE IF NOT EXISTS "public"."user_passkeys" (
    "id" integer DEFAULT nextval('user_passkeys_id_seq') NOT NULL,
    "user_id" integer NOT NULL,
    "credential_id" text NOT NULL,
    "public_key" text NOT NULL,
    "name" character varying(255) NOT NULL,
    "sign_count" bigint DEFAULT 0 NOT NULL,
    "transports" text,
    "aaguid" character varying(64),
    "created_at" timestamp DEFAULT CURRENT_TIMESTAMP NOT NULL,
    "last_used_at" timestamp,
    CONSTRAINT "user_passkeys_pkey" PRIMARY KEY ("id"),
    CONSTRAINT "fk_user_passkeys_users" FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS "idx_user_passkeys_credential_id" ON "public"."user_passkeys" USING btree ("credential_id");
CREATE INDEX IF NOT EXISTS "idx_user_passkeys_user_id" ON "public"."user_passkeys" USING btree ("user_id");
