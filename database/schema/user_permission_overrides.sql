BEGIN;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS permission_overrides JSONB NOT NULL DEFAULT '{}'::JSONB;

ALTER TABLE users
    DROP CONSTRAINT IF EXISTS users_permission_overrides_object_check;

ALTER TABLE users
    ADD CONSTRAINT users_permission_overrides_object_check
        CHECK (JSONB_TYPEOF(permission_overrides) = 'object');

COMMENT ON COLUMN users.permission_overrides IS
    'Per-user menu/API overrides: ability key => true (grant), false (deny), missing (inherit role)';

COMMIT;
