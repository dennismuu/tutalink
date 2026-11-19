USE tutalink;

ALTER TABLE users
    ADD COLUMN `role` VARCHAR(32) NULL AFTER role_id;

UPDATE users u
JOIN roles r ON r.id = u.role_id
SET u.`role` = r.slug;

ALTER TABLE users
    MODIFY COLUMN `role` VARCHAR(32) NOT NULL,
    ADD KEY idx_users_role (`role`);
