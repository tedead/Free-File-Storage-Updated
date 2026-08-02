/* =============================================================================
   Free File Storage - stored procedures

   Every statement the application runs lives here. PHP holds no SQL text at all;
   it only issues {CALL ffs.Something(?, ?)}.

   Two rules make this worth doing, and neither is automatic:

     1. No dynamic SQL. Not one EXEC(@sql) or sp_executesql with a concatenated
        string anywhere in this file. A procedure that builds SQL from its
        parameters is exactly as injectable as the old inline queries were --
        moving the concatenation into the database does not sanitise it.

     2. Parameters are the only way in. T-SQL's @ prefix also removes a whole bug
        class the original hit: in the old ffs_delFile the parameter was named
        FileID, identical to the column, so "WHERE f.FileID = FileID" resolved to
        column = column -- always true. That procedure deleted every row in the
        files table on any call. @FileID cannot collide with a column name.

   The payoff is in 03_security.sql: because the app only ever needs EXECUTE, it
   can be granted EXECUTE and nothing else. A SQL injection flaw that somehow
   survived would still be unable to SELECT the users table directly.
   ============================================================================= */

USE file_storage;
GO

-- -----------------------------------------------------------------------------
-- Authentication
-- -----------------------------------------------------------------------------

CREATE OR ALTER PROCEDURE ffs.GetUserForLogin
    @UserName NVARCHAR(64)
AS
BEGIN
    SET NOCOUNT ON;

    /* Returns the hash for PHP to verify with password_verify(). The old code
       asked the database "does a row exist with this username AND password",
       which required storing the password in a comparable (i.e. plaintext)
       form. Comparison has to happen in PHP for hashing to be possible. */
    SELECT UserID, UserName, DisplayName, PasswordHash
    FROM   dbo.users
    WHERE  UserName = @UserName;
END;
GO

CREATE OR ALTER PROCEDURE ffs.TouchLastLogin
    @UserID UNIQUEIDENTIFIER
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE dbo.users SET LastLoginUtc = SYSUTCDATETIME() WHERE UserID = @UserID;
END;
GO

CREATE OR ALTER PROCEDURE ffs.UpdatePasswordHash
    @UserID       UNIQUEIDENTIFIER,
    @PasswordHash VARCHAR(255)
AS
BEGIN
    SET NOCOUNT ON;
    /* Called when password_needs_rehash() reports the stored hash used an older
       cost factor or algorithm than the current PHP default. */
    UPDATE dbo.users SET PasswordHash = @PasswordHash WHERE UserID = @UserID;
END;
GO

-- -----------------------------------------------------------------------------
-- Registration
-- -----------------------------------------------------------------------------

CREATE OR ALTER PROCEDURE ffs.CreateUser
    @UserID       UNIQUEIDENTIFIER,
    @FirstName    NVARCHAR(100),
    @LastName     NVARCHAR(100),
    @Email        NVARCHAR(254),
    @DisplayName  NVARCHAR(100),
    @UserName     NVARCHAR(64),
    @PasswordHash VARCHAR(255),
    @Status       VARCHAR(16) OUTPUT   -- 'ok' | 'username_taken' | 'email_taken'
AS
BEGIN
    SET NOCOUNT ON;
    SET @Status = 'ok';

    /* No "SELECT then INSERT" check here. That pattern is a race: two requests
       can both see "free" before either inserts. The unique indexes decide, and
       we translate the violation into a status the UI can show. */
    BEGIN TRY
        INSERT INTO dbo.users
            (UserID, FirstName, LastName, Email, DisplayName, UserName, PasswordHash)
        VALUES
            (@UserID, @FirstName, @LastName, @Email, @DisplayName, @UserName, @PasswordHash);
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() IN (2601, 2627)   -- duplicate key
        BEGIN
            SET @Status = CASE
                WHEN EXISTS (SELECT 1 FROM dbo.users WHERE UserName = @UserName)
                    THEN 'username_taken'
                ELSE 'email_taken'
            END;
        END
        ELSE
            THROW;
    END CATCH;
END;
GO

-- -----------------------------------------------------------------------------
-- Files
-- -----------------------------------------------------------------------------

CREATE OR ALTER PROCEDURE ffs.ListUserFiles
    @UserID UNIQUEIDENTIFIER
AS
BEGIN
    SET NOCOUNT ON;

    /* Scoped to one user by parameter. The old dashboard query joined on the
       session username and the delete/fetch pages trusted a user id supplied in
       a form field, so any logged-in user could name someone else's id. The
       caller cannot express "another user's files" through this interface. */
    SELECT f.FileID, f.Name, f.Size, f.ContentType, f.Category, f.DateCreated
    FROM   dbo.files f
    JOIN   dbo.user_files uf ON uf.FileID = f.FileID
    WHERE  uf.UserID = @UserID
    ORDER  BY f.DateCreated DESC, f.Name ASC;
END;
GO

CREATE OR ALTER PROCEDURE ffs.AddFile
    @FileID      UNIQUEIDENTIFIER,
    @UserID      UNIQUEIDENTIFIER,
    @Name        NVARCHAR(255),
    @Size        BIGINT,
    @ContentType NVARCHAR(255),
    @Category    NVARCHAR(32),
    @StoredPath  NVARCHAR(400)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    /* Both inserts or neither. The original ran them as two separate mysqli
       connections against MyISAM, so a failure on the second left an orphaned
       files row that belonged to nobody and could never be listed or deleted. */
    BEGIN TRANSACTION;

        INSERT INTO dbo.files (FileID, Name, Size, ContentType, Category, StoredPath)
        VALUES (@FileID, @Name, @Size, @ContentType, @Category, @StoredPath);

        INSERT INTO dbo.user_files (FileID, UserID)
        VALUES (@FileID, @UserID);

    COMMIT TRANSACTION;
END;
GO

CREATE OR ALTER PROCEDURE ffs.GetUserFile
    @UserID UNIQUEIDENTIFIER,
    @FileID UNIQUEIDENTIFIER
AS
BEGIN
    SET NOCOUNT ON;

    /* The ownership predicate is part of the lookup, not a separate check the
       caller might forget. A file id belonging to someone else returns no rows. */
    SELECT f.FileID, f.Name, f.Size, f.ContentType, f.Category, f.StoredPath
    FROM   dbo.files f
    JOIN   dbo.user_files uf ON uf.FileID = f.FileID
    WHERE  uf.UserID = @UserID
      AND  f.FileID  = @FileID;
END;
GO

CREATE OR ALTER PROCEDURE ffs.DeleteFile
    @UserID     UNIQUEIDENTIFIER,
    @FileID     UNIQUEIDENTIFIER,
    @StoredPath NVARCHAR(400) OUTPUT,  -- path to unlink, or NULL if nothing to unlink
    @Deleted    BIT           OUTPUT   -- 0 = not found or not owned by @UserID
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @StoredPath = NULL;
    SET @Deleted    = 0;

    BEGIN TRANSACTION;

        /* Ownership and existence in one predicate. */
        SELECT @StoredPath = f.StoredPath
        FROM   dbo.files f
        JOIN   dbo.user_files uf ON uf.FileID = f.FileID
        WHERE  uf.UserID = @UserID
          AND  f.FileID  = @FileID;

        IF @StoredPath IS NULL
        BEGIN
            COMMIT TRANSACTION;
            RETURN;
        END

        DELETE FROM dbo.user_files WHERE FileID = @FileID AND UserID = @UserID;

        SET @Deleted = 1;

        /* user_files is many-to-many, so only drop the row -- and tell the caller
           to unlink the bytes -- once the last owner has let go. */
        IF NOT EXISTS (SELECT 1 FROM dbo.user_files WHERE FileID = @FileID)
            DELETE FROM dbo.files WHERE FileID = @FileID;
        ELSE
            SET @StoredPath = NULL;

    COMMIT TRANSACTION;
END;
GO
