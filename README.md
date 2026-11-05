\# Docker Lab 4



Naseem Widad



This lab adds administrator accounts, role-based access, email verification, authenticator login, password resets, and Bootstrap styling to the previous Docker project.



\## Setup



You need Docker Desktop, Git, Windows PowerShell, and Mailtrap SMTP credentials.



Clone the project:



```powershell

git clone https://github.com/nwidad123/DockerLab-Lab4.git

Set-Location DockerLab-Lab4

Copy-Item .env.example .env

notepad .env

```



Fill in the database and SMTP settings in `.env`. Do not commit this file.



With Docker Desktop running, start the application:



```powershell

powershell -NoProfile -ExecutionPolicy Bypass -File .\\scripts\\Start-Lab.ps1

```



Open http://localhost:8080.



The script starts the containers and runs the PHP syntax checks, authenticator tests, and web smoke tests.



\## Database setup



MySQL runs `database/schema.sql` when the database data directory is empty. This creates the users and password-reset tables.



Database files are stored in `db\_data` and remain there when the containers restart. Changes to `schema.sql` do not automatically apply to an existing database. Use a migration for those changes instead of deleting `db\_data`.



\## First administrator



New accounts start as regular users. Admin applications need approval from an existing administrator.



For a fresh installation, register the first administrator's account, verify the email, and complete authenticator setup. The database operator then needs to promote that account manually.



Open MySQL:



```powershell

docker exec -it lab\_mysql mysql -uroot -p

```



Enter the root password from `.env` and select the database:



```sql

USE lab\_db;

```



If you changed `MYSQL\_DATABASE`, use that name instead.



Check for an existing administrator:



```sql

SELECT userID, Email FROM users WHERE Role = 'admin';

```



If one exists, use that account to approve applications through the admin page.



If no administrator exists, replace the email below with the intended account's email:



```sql

UPDATE users

SET Role = 'admin', AdminRequestStatus = 'approved'

WHERE Email = 'replace-with-your-email@example.com'

&#x20; AND EmailVerified = 1

&#x20; AND TotpSecret IS NOT NULL

&#x20; AND TotpSecret <> '';

```



Confirm that exactly one account was updated, then exit:



```sql

exit;

```



Sign in with that account to access admin management. Later applications can be approved or rejected from the admin page.



\## Features



\- Account registration and email verification

\- Authenticator setup and login

\- Administrator applications and approval

\- Admin access revocation

\- Dashboard with account details and login timestamps

\- Password reset through an expiring, single-use email link

\- Bootstrap pages and a scrollable admin table



The admin page checks the current role in the database before allowing access.



\## Database changes



Back up the database before applying a migration.



Open a shell in the database container:



```powershell

docker exec -it lab\_mysql sh

```



Run:



```sh

mysqldump -uroot -p --single-transaction --no-tablespaces --databases "$MYSQL\_DATABASE" > /tmp/lab4-before-migration.sql

```



Enter the root password. If the command succeeds, exit the shell:



```sh

exit

```



Copy the backup outside the repository:



```powershell

docker cp lab\_mysql:/tmp/lab4-before-migration.sql ..\\lab4-before-migration.sql

```



Keep this backup private. Use a new filename for each backup.



To apply the password-reset migration:



```powershell

docker cp .\\database\\migrations\\002\_password\_reset.sql lab\_mysql:/tmp/002\_password\_reset.sql

docker exec -it lab\_mysql mysql -uroot -p

```



Then run:



```sql

USE lab\_db;

SOURCE /tmp/002\_password\_reset.sql;

SHOW COLUMNS FROM password\_reset\_tokens;

exit;

```



Use your configured database name if it differs from `lab\_db`.



This migration has already been applied to the development database. It creates the password-reset table if it does not exist.



For future database changes, add a numbered migration and update `database/schema.sql`. Test the migration against an existing database and test the full schema with an empty database.



\## Testing



Run all local checks:



```powershell

powershell -NoProfile -ExecutionPolicy Bypass -File .\\scripts\\Start-Lab.ps1

```



To run the checks separately, leave the containers running and use the commands below.



PHP syntax:



```powershell

powershell -NoProfile -ExecutionPolicy Bypass -File .\\scripts\\Test-Php.ps1

```



Authenticator unit tests:



```powershell

docker cp .\\tests\\authenticator.php lab\_php:/tmp/lab4-authenticator.php

docker exec lab\_php php /tmp/lab4-authenticator.php /usr/share/nginx/html/GoogleAuthenticator.php

```



Web smoke tests:



```powershell

docker cp .\\tests\\smoke.php lab\_php:/tmp/lab4-smoke.php

docker exec lab\_php php /tmp/lab4-smoke.php http://web-server

```



Results from testing:



\- 17 PHP files passed syntax checks.

\- 26 authenticator tests passed.

\- 22 web smoke checks passed.

\- Fresh database initialization passed in GitHub Actions.



The authenticator tests check reference codes, time tolerance, invalid codes, and secret generation.



The smoke tests check public pages, Bootstrap, visible PHP errors, login redirects, and malformed tokens. Registration, login, admin approval and revocation, password resets, and table scrolling were also tested manually.



\## GitHub Actions



The repository has two workflows:



\- `ci.yml`: builds the PHP image, checks PHP syntax, tests a fresh database, and runs web smoke tests.

\- `unit-tests.yml`: runs the authenticator unit tests.



Both run on pushes and pull requests to `main`. They can also be started manually.



The workflows use temporary test credentials. They do not use the local `.env` or development database.



\## Updating the application



1\. Create a branch for the changes.

2\. Update the code and relevant tests.

3\. Add a migration if the database structure changes.

4\. Run the local checks.

5\. Push the branch and open a pull request.

6\. Review the changes and wait for both workflows to pass.

7\. Merge into `main`.



Deployment is currently manual. Back up the target database, obtain the reviewed code, apply any needed migrations, restart the application, and test the affected features.



Plan downtime for changes that are incompatible with the running application. Reverting the code does not reverse a database migration.



GitHub Actions handles continuous integration. Automatic cloud deployment has not been added.



\## Stopping the application



```powershell

docker compose stop

```



Use `scripts/Start-Lab.ps1` to start it again.



\## Before cloud deployment



The local setup still needs deployment configuration before it is hosted publicly:



\- HTTPS and the correct application URL

\- Credentials stored in a secret manager

\- A configured email service

\- Persistent database storage and backups

\- Review of session handling and login rate limits

\- Application files packaged for deployment

\- A deployment workflow with approval and rollback steps

