CREATE TABLE IF NOT EXISTS password_reset_tokens (
    userID INT NOT NULL,
    TokenHash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ExpiresAt DATETIME NOT NULL,

    PRIMARY KEY (userID),
    UNIQUE KEY uq_password_reset_token (TokenHash),

    CONSTRAINT fk_password_reset_user
        FOREIGN KEY (userID)
        REFERENCES users (userID)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci;