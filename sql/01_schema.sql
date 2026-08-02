/* =============================================================================
   Free File Storage - schema (Microsoft SQL Server 2016+)

   Run order:  01_schema.sql  ->  02_procedures.sql  ->  03_security.sql

   Changes from the original MySQL schema:
     - MyISAM had no foreign keys or transactions. InnoDB-equivalent behaviour is
       the only option here, so the parent/child links are now real constraints.
     - UserID/FileID were varchar(38) holding COM GUID strings. UNIQUEIDENTIFIER
       stores the same value in 16 bytes and rejects malformed input.
     - varchar/utf8 -> NVARCHAR. utf8 in MySQL 5.x was only 3 bytes per char and
       silently mangled anything outside the BMP (emoji in filenames, for one).
     - Password was varchar(100) holding the plaintext. It is now a bcrypt hash.
     - Tables live in dbo; all stored procedures live in the ffs schema. Both are
       owned by dbo so ownership chaining lets the app account execute the
       procedures while holding no permission at all on the tables themselves.
   ============================================================================= */

IF DB_ID(N'file_storage') IS NULL
    CREATE DATABASE file_storage;
GO

USE file_storage;
GO

IF SCHEMA_ID(N'ffs') IS NULL
    EXEC(N'CREATE SCHEMA ffs AUTHORIZATION dbo;');
GO

/* Drop children before parents so the FKs do not block us on a re-run. */
DROP TABLE IF EXISTS dbo.user_files;
DROP TABLE IF EXISTS dbo.files;
DROP TABLE IF EXISTS dbo.users;
GO

CREATE TABLE dbo.users (
    UserID        UNIQUEIDENTIFIER NOT NULL CONSTRAINT PK_users PRIMARY KEY,
    FirstName     NVARCHAR(100)    NOT NULL CONSTRAINT DF_users_FirstName DEFAULT N'',
    LastName      NVARCHAR(100)    NOT NULL CONSTRAINT DF_users_LastName  DEFAULT N'',
    Email         NVARCHAR(254)    NOT NULL,   -- 254 is the RFC 5321 maximum
    DisplayName   NVARCHAR(100)    NOT NULL,
    UserName      NVARCHAR(64)     NOT NULL,
    PasswordHash  VARCHAR(255)     NOT NULL,   -- bcrypt is ASCII; 255 leaves room for argon2id
    DateCreated   DATETIME2(0)     NOT NULL CONSTRAINT DF_users_DateCreated DEFAULT SYSUTCDATETIME(),
    LastLoginUtc  DATETIME2(0)     NULL
);
GO

/* The original had no uniqueness on UserName: check.php did a SELECT first and
   raced anyone registering at the same moment. Let the engine enforce it. */
CREATE UNIQUE INDEX UX_users_UserName ON dbo.users(UserName);
CREATE UNIQUE INDEX UX_users_Email    ON dbo.users(Email);
GO

CREATE TABLE dbo.files (
    FileID        UNIQUEIDENTIFIER NOT NULL CONSTRAINT PK_files PRIMARY KEY,
    Name          NVARCHAR(255)    NOT NULL,   -- original filename, shown to the user
    Size          BIGINT           NOT NULL CONSTRAINT CK_files_Size CHECK (Size >= 0),
    ContentType   NVARCHAR(255)    NOT NULL,
    Category      NVARCHAR(32)     NOT NULL,
    StoredPath    NVARCHAR(400)    NOT NULL,   -- path relative to the storage root
    DateCreated   DATETIME2(0)     NOT NULL CONSTRAINT DF_files_DateCreated DEFAULT SYSUTCDATETIME()
);
GO

CREATE TABLE dbo.user_files (
    FileID        UNIQUEIDENTIFIER NOT NULL,
    UserID        UNIQUEIDENTIFIER NOT NULL,
    DateCreated   DATETIME2(0)     NOT NULL CONSTRAINT DF_user_files_DateCreated DEFAULT SYSUTCDATETIME(),
    CONSTRAINT PK_user_files PRIMARY KEY (FileID, UserID),
    CONSTRAINT FK_user_files_files
        FOREIGN KEY (FileID) REFERENCES dbo.files(FileID) ON DELETE CASCADE,
    CONSTRAINT FK_user_files_users
        FOREIGN KEY (UserID) REFERENCES dbo.users(UserID) ON DELETE CASCADE
);
GO

/* The dashboard lists one user's files newest-first; this covers that query. */
CREATE INDEX IX_user_files_UserID ON dbo.user_files(UserID) INCLUDE (FileID);
GO
