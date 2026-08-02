/* =============================================================================
   Remove test accounts created while verifying the application.

   Run this with an administrative login (sysadmin / db_owner), NOT as ffs_app.
   ffs_app is deliberately denied direct DELETE on these tables and can only
   execute the ffs.* procedures, none of which delete a user -- so it cannot
   perform this cleanup. That is the least-privilege design working as intended,
   not an oversight.

   Three accounts named ffs_testuser_* were created by automated end-to-end
   tests on 2026-07-25 against http://localhost:8080/Free-File-Storage-Updated
   (two verifying the upload/download/delete journey, one verifying the bcrypt
   cost change). Their uploaded files were already deleted through the UI, so
   this only clears the user rows. The count is reported when it runs, so it
   stays correct if you have added or removed test accounts since.

   Verify before deleting:

       SELECT UserID, UserName, Email, DateCreated
       FROM   dbo.users
       WHERE  UserName LIKE 'ffs_testuser[_]%';
   ============================================================================= */

-- Change this if your database is not named file_storage (the name created by
-- 01_schema.sql).
USE file_storage;
GO

SET NOCOUNT ON;

DECLARE @doomed TABLE (UserID UNIQUEIDENTIFIER);

INSERT INTO @doomed (UserID)
SELECT UserID
FROM   dbo.users
WHERE  UserName LIKE 'ffs_testuser[_]%';   -- [_] escapes the underscore wildcard

PRINT 'Test accounts found: ' + CAST((SELECT COUNT(*) FROM @doomed) AS VARCHAR);

/* Any files those accounts still own, and the join rows.
   user_files cascades from users, but files does not -- it is only reachable
   through the join table -- so clear it explicitly to avoid orphaned rows. */
DELETE f
FROM   dbo.files f
JOIN   dbo.user_files uf ON uf.FileID = f.FileID
WHERE  uf.UserID IN (SELECT UserID FROM @doomed);

PRINT 'Orphaned file rows removed: ' + CAST(@@ROWCOUNT AS VARCHAR);

DELETE FROM dbo.user_files WHERE UserID IN (SELECT UserID FROM @doomed);
DELETE FROM dbo.users      WHERE UserID IN (SELECT UserID FROM @doomed);

PRINT 'Test accounts removed: ' + CAST(@@ROWCOUNT AS VARCHAR);
GO

/* Confirm nothing is left behind. */
SELECT 'users remaining'      AS item, COUNT(*) AS n FROM dbo.users
UNION ALL SELECT 'files remaining',      COUNT(*) FROM dbo.files
UNION ALL SELECT 'user_files remaining', COUNT(*) FROM dbo.user_files;
GO
