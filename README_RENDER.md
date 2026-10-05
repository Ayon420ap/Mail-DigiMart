# Render-ready Telegram PHP Bot

## Files
- `Bot2_Deposit_Cancel_Updated.php` - bot code. JSON state functions are backed by PostgreSQL.
- `Dockerfile` - PHP 8.3 + PostgreSQL + cURL + ZIP extensions.
- `entrypoint.sh` - sets Telegram webhook on startup and starts PHP on Render's PORT.
- `set_webhook.php` - calls Telegram `setWebhook`.
- `render.yaml` - optional Render Blueprint for Web Service + PostgreSQL.

## Render Environment Variables
Set these on the Web Service:
- `BOT_TOKEN` = BotFather token
- `DATABASE_URL` = Render PostgreSQL internal connection string (the Blueprint can wire this automatically)
- `WEBHOOK_URL` = `https://YOUR-SERVICE.onrender.com/Bot2_Deposit_Cancel_Updated.php`
- `WEBHOOK_SECRET` = a long random string (optional but recommended)

Do not commit BOT_TOKEN or DATABASE_URL to GitHub.

## Deploy
1. Push all files to GitHub.
2. In Render, create a Web Service from the repo and choose Docker.
3. If using `render.yaml`, deploy the Blueprint instead.
4. Set the environment variables.
5. Deploy.
6. Open `https://YOUR-SERVICE.onrender.com/Bot2_Deposit_Cancel_Updated.php` in a browser. It should return JSON with `ok: true`.
7. The container startup script sets the Telegram webhook automatically.

## Data
The bot's existing JSON-style storage API has been retained, but its data is stored in PostgreSQL table `bot_json_store`. If legacy `data/*.json` files are present on first connection and the corresponding database key does not exist, they are imported once.
