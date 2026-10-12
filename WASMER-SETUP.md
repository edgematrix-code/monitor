# Visitor Monitor on Wasmer (MySQL version)

Wasmer wipes the app's local disk on every deploy, so this package stores everything (visits and your login)
in the **managed MySQL database** that Wasmer provides. No SQLite file is used.

You need: a free Wasmer account and the Wasmer CLI (https://docs.wasmer.io/install).

## Steps

1. Log in and create a PHP app from Wasmer's starter template:
   ```
   wasmer login
   mkdir visitor-monitor
   cd visitor-monitor
   wasmer deploy --template=php-starter
   ```
   Answer the prompts (owner and app name). This creates an `app` folder and an `app.yaml` file.

2. Delete everything inside the generated `app` folder (`index.php`, `info.php`), then copy in the five files
   from the `app` folder of this package: `index.php`, `track.php`, `tracker.js`, `lib.php`, `config.php`.

3. Open `app.yaml` and add these lines at the end (if the file already has a `capabilities:` section,
   put `database:` inside it instead of adding a second one):
   ```yaml
   capabilities:
     database:
       engine: mysql

   scaling:
     mode: single_concurrency
   ```
   The first block asks Wasmer to create the database and pass its login details to the app automatically.
   The second tells Wasmer that PHP handles one request at a time, which is what Wasmer's PHP guide recommends.

4. Deploy again:
   ```
   wasmer deploy
   ```

5. Open your app's address (for example `https://your-app.wasmer.app`). The first time, it asks you to
   **create a password**. Do this right away, because the first visitor chooses it.

6. The dashboard shows a tracking line that looks like
   `<script defer src="https://your-app.wasmer.app/tracker.js"></script>`.
   Paste it just before `</head>` on every page of your real website.

7. Visit your website, then refresh the dashboard to see yourself counted.

## Good to know
- **Database region:** Wasmer databases exist only in some regions (us-losa1, fr-roub1, ca-beau1 at the time of writing).
  If you want to choose, add `locality:` with exactly one of them under `regions:` in `app.yaml`
  (https://docs.wasmer.io/edge/learn/databases). Otherwise Wasmer picks a default.
- **Data is kept** across deploys. You can inspect it with the "Go to DB Explorer" button on your app's Databases tab
  in the Wasmer dashboard. Wasmer also keeps automatic database backups.
- **Lock it down:** once it works, open `app/config.php`, and list your website in `allowed_hosts`
  (for example `['yourwebsite.com']`) so nobody else can send fake visits. Redeploy with `wasmer deploy`.
- **If the dashboard says "Cannot reach the database":** check the Databases tab of your app in the Wasmer dashboard
  to confirm a MySQL database was created, then redeploy.
- Wasmer's PHP runs on PHP's built-in server, so `.htaccess` files are not used. That is fine here, because no data
  is stored in files.
