BEGIN;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS preferences JSONB NOT NULL DEFAULT '{}'::JSONB;

ALTER TABLE users
    DROP CONSTRAINT IF EXISTS users_preferences_object_check;

ALTER TABLE users
    ADD CONSTRAINT users_preferences_object_check
        CHECK (JSONB_TYPEOF(preferences) = 'object');

COMMENT ON COLUMN users.preferences IS
    'Per-user UI, locale, document, table, navigation, and notification preferences.';

COMMIT;
