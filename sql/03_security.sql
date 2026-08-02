/* =============================================================================
   Free File Storage - database security

   The original created four MySQL accounts (user_check, user_insert,
   user_select, user_delete) that all shared the password 'userPass'. Each was
   granted a global privilege on *.* -- every database on the server, including
   mysql.user -- and then, on the line after, GRANT ALL PRIVILEGES on
   file_storage.*. So the four-account split bought nothing: any one of them
   could drop the schema, and all four were interchangeable.

   Replaced with one account that can execute the procedures in the ffs schema
   and do nothing else. Ownership chaining (dbo owns both dbo and ffs) lets the
   procedures read and write the tables even though the account holds no
   permission on them. Verify with the checks at the bottom of this file.

   >>> Set a real password before running this. <<<
   Better still, use Windows authentication and skip the password entirely --
   see the note at the end.
   ============================================================================= */

/* The password is supplied as a sqlcmd variable, so it is never written into
   this file and cannot be committed by accident:

       sqlcmd -S <server> -E -b -v FFS_APP_PASSWORD="your password here" -i 03_security.sql

   In SQL Server Management Studio, enable SQLCMD Mode (Query menu) first, or
   uncomment the :setvar line below and delete it again afterwards.

   The value is checked before use, so a forgotten -v fails loudly instead of
   creating an account with a literal password of "$(FFS_APP_PASSWORD)". */

-- :setvar FFS_APP_PASSWORD "your password here"

USE master;
GO

IF '$(FFS_APP_PASSWORD)' IN ('', 'CHANGE-ME-before-running')
BEGIN
    RAISERROR('Pass the password with:  sqlcmd -v FFS_APP_PASSWORD="..." -i 03_security.sql', 20, 1) WITH LOG;
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'ffs_app')
BEGIN
    CREATE LOGIN ffs_app
        WITH PASSWORD     = N'$(FFS_APP_PASSWORD)',
             CHECK_POLICY = ON;   -- defers to the Windows password policy
END
GO

USE file_storage;
GO

IF NOT EXISTS (SELECT 1 FROM sys.database_principals WHERE name = N'ffs_app')
    CREATE USER ffs_app FOR LOGIN ffs_app;
GO

/* The whole permission set. EXECUTE on the procedure schema, nothing else. */
GRANT EXECUTE ON SCHEMA::ffs TO ffs_app;
GO

/* Explicit denies so a future GRANT elsewhere cannot quietly widen this.
   DENY outranks GRANT in SQL Server, but it does NOT break ownership chaining:
   the procedures still work, because chained access skips the permission check
   on the tables entirely. Direct SELECT from the app account stays blocked. */
DENY SELECT, INSERT, UPDATE, DELETE, ALTER ON SCHEMA::dbo TO ffs_app;
GO

/* Reading other people's definitions is not needed to call them. */
DENY VIEW DEFINITION ON SCHEMA::dbo TO ffs_app;
GO


/* -----------------------------------------------------------------------------
   Verification -- run these as ffs_app to confirm the boundary holds.

       EXECUTE AS USER = 'ffs_app';

       -- expected: permission denied on object 'users'
       SELECT TOP 1 UserName, PasswordHash FROM dbo.users;

       -- expected: succeeds and returns rows
       EXEC ffs.GetUserForLogin @UserName = N'someone';

       REVERT;

   If the SELECT succeeds, ownership chaining is not the reason -- something has
   granted the account direct table rights. Check with:

       SELECT p.permission_name, p.state_desc, o.name
       FROM   sys.database_permissions p
       LEFT   JOIN sys.objects o ON o.object_id = p.major_id
       WHERE  p.grantee_principal_id = DATABASE_PRINCIPAL_ID('ffs_app');
   ----------------------------------------------------------------------------- */


/* -----------------------------------------------------------------------------
   Preferred alternative: Windows authentication.

   With IIS or Apache running the site under a dedicated service account, the
   application needs no database password at all -- there is then no credential
   in config, in source control, or in a backup to leak. Leave FFS_DB_USER unset
   and the PHP connects with Trusted_Connection.

       CREATE LOGIN [DOMAIN\svc_ffs] FROM WINDOWS;
       USE file_storage;
       CREATE USER [DOMAIN\svc_ffs] FOR LOGIN [DOMAIN\svc_ffs];
       GRANT EXECUTE ON SCHEMA::ffs TO [DOMAIN\svc_ffs];
       DENY SELECT, INSERT, UPDATE, DELETE, ALTER ON SCHEMA::dbo TO [DOMAIN\svc_ffs];
   ----------------------------------------------------------------------------- */
