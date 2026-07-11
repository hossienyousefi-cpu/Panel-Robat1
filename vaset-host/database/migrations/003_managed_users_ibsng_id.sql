-- Direct-HTTP IBSng integration: IBSng's admin panel identifies users by an internal
-- numeric user_id (distinct from the username), needed to edit/lock/delete a user
-- without a fragile username search round-trip against IBSng on every action.
-- Storing it once at creation time lets DirectHttpGateway skip that search entirely.

ALTER TABLE managed_users
    ADD COLUMN ibsng_user_id INT UNSIGNED NULL AFTER ibsng_username;
