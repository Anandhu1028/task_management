# Task Management Desktop

This Electron app uses the existing Laravel application as its API and database source. It does not connect directly to the database or use mock task records.

Run the app with Electron (`npm start` during development) or launch the installed Task Management app. Do not open `renderer/index.html` directly in Chrome/Edge: the renderer requires the Electron preload bridge for secure API requests. The packaged API base URL is `https://tracker-manage.focuzacademy.in/api`.

## Local development

1. Start the existing Laravel app and database. For a Laragon virtual host, use that host's `/api` URL; otherwise start Laravel with:

   ```powershell
   php artisan serve --host=127.0.0.1 --port=8000
   ```

2. Install desktop dependencies and prepare the local configuration:

   ```powershell
   cd desktop
   npm install
   Copy-Item .env.example .env
   ```

3. Set `TASK_API_BASE_URL` in `desktop/.env` to the Laravel API URL, such as `http://127.0.0.1:8000/api`, then run:

   ```powershell
   npm run check
   npm start
   ```

   In development, the login screen can persist a local API URL in the current Windows user's Electron application data. Changing that URL clears the locally stored session so its bearer token cannot be sent to a different host. The URL is configuration, not a credential.

## Windows installer

The Windows installer bundles the production API URL in `resources/api-config.json` and reads it from `process.resourcesPath`.
Every packaged API request is locked to that validated production URL; saved per-user settings and development environment variables cannot override it. The packaged sign-in screen rejects attempts to change the API URL. Existing localhost settings from older app versions are ignored.

With dependencies installed, run:

```powershell
npm run check
npm run build:win
```

The build command validates JavaScript syntax, production URL resolution (including stale localhost settings), and the production API configuration before packaging.
The NSIS installer is written to `desktop/dist/task-management-setup-1.0.0.exe`. Verify `desktop/dist/win-unpacked/resources/api-config.json` contains the production API URL.

## Uploading the installer to Hostinger

Build the installer on Windows, not on the Hostinger server:

```powershell
Set-Location C:\laragon\www\task_management\desktop
npm run check
npm run build:win
```

In the Hostinger SSH session, from the Laravel project root shown in the deployment setup:

```sh
mkdir -p public/downloads
chmod 755 public/downloads
```

Use hPanel File Manager to upload `desktop/dist/task-management-setup-1.0.0.exe` from Windows into the matching server directory `public/downloads`, then set the file permissions and verify it:

```sh
chmod 644 public/downloads/task-management-setup-1.0.0.exe
ls -lh public/downloads/task-management-setup-1.0.0.exe
curl -I https://tracker-manage.focuzacademy.in/downloads/task-management-setup-1.0.0.exe
```

The public URL must return HTTP 200. If it returns Laravel's 404 after the file exists, confirm in hPanel that the subdomain document root is the Laravel `public` directory; do not replace `.htaccess` blindly. The local build does not upload the installer or change Hostinger settings.

## Backend endpoints

The client uses Laravel's existing desktop API:

- `POST /api/auth/login`, `GET /api/auth/user`, `POST /api/auth/logout`
- `GET /api/desktop/meta`
- `/api/tasks` for task listing, creation, details, updates, status actions, and work updates
- `/api/tasks/{task}/subtasks` for subtask creation, updates, and completion toggles

These endpoints retain Laravel policies, request validation, active-account checks, and task visibility scoping.

The desktop task list uses Laravel's supported search, scope, organization, assignee, status, priority, task-type, deadline-condition, sort, and pagination parameters. Task actions and subtask controls are shown from each task's API-provided permissions; Laravel remains authoritative and may reject actions if permissions change. This API does not currently expose a task-delete route, so the desktop client does not offer a delete action.

Tasks are not stored in a desktop database. Task reads and mutations go through the authenticated Laravel API; after a successful mutation the client reloads task-list counts and fetches the affected task again. Laravel authorization, validation, database transactions, and production database configuration remain server-side.

The task toolbar keeps search, Filters, and New task on one row. Filters opens a dialog styled consistently with the task form; selections are applied together with **Apply filters**, discarded with **Cancel**, or reset and applied with **Clear filters**.
