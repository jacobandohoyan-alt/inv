-- Run ONCE in phpMyAdmin (database: abuzodb) so passwords can be stored as bcrypt hashes.
-- Old md5 accounts keep working and are upgraded automatically the next time they log in.
ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL;

-- Optional but recommended: no two accounts with the same username.
-- (If this fails, there are duplicate usernames: delete or rename them first.)
ALTER TABLE users ADD UNIQUE KEY uq_users_username (username);
