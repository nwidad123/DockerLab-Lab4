CREATE TABLE IF NOT EXISTS users (
    userID INT NOT NULL AUTO_INCREMENT,
    FirstName VARCHAR(100) NOT NULL,
    LastName VARCHAR(100) NOT NULL,
    Email VARCHAR(255) NOT NULL,
    Password VARCHAR(255) NOT NULL,
    Status INT DEFAULT 0,
    RegDatetime DATETIME DEFAULT CURRENT_TIMESTAMP,
    LoginDatetime DATETIME DEFAULT NULL,
    LogoutDatetime DATETIME DEFAULT NULL,

    EmailVerified INT DEFAULT 0,
    EmailVerificationToken VARCHAR(64) DEFAULT NULL,

    TwoFactorPin VARCHAR(6) DEFAULT NULL,
    TwoFactorCreatedAt DATETIME DEFAULT NULL,
    TotpSecret VARCHAR(32) DEFAULT NULL,

    Role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    AdminRequestStatus ENUM(
        'none',
        'pending',
        'approved',
        'rejected',
        'revoked'
    ) NOT NULL DEFAULT 'none',

    PRIMARY KEY (userID),
    UNIQUE KEY Email (Email)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_0900_ai_ci;
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
