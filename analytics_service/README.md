# Mobimend Analytics Service

Lightweight FastAPI analytics service for predictive dashboard signals.

## Local Run

```bash
cd analytics_service
python -m venv .venv
. .venv/bin/activate
pip install -r requirements.txt
export DATABASE_URL='mysql+pymysql://user:password@127.0.0.1:3306/mobimend?charset=utf8mb4'
uvicorn main:app --host 127.0.0.1 --port 8001
```

## VPS Deployment

Run on the same VPS as PHP, bound to localhost port `8001`, then proxy through nginx only if you want external diagnostics. The PHP dashboard calls `http://localhost:8001/api/analytics/...` directly.

Systemd unit example:

```ini
[Unit]
Description=Mobimend FastAPI Analytics
After=network.target mysql.service

[Service]
WorkingDirectory=/var/www/Mobimend/analytics_service
Environment=DATABASE_URL=mysql+pymysql://mobimend_user:change_this_password@127.0.0.1:3306/mobimend?charset=utf8mb4
ExecStart=/var/www/Mobimend/analytics_service/.venv/bin/uvicorn main:app --host 127.0.0.1 --port 8001
Restart=always
RestartSec=5
User=www-data
Group=www-data

[Install]
WantedBy=multi-user.target
```

Nginx location example:

```nginx
location /analytics/ {
    proxy_pass http://127.0.0.1:8001/;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

## Endpoints

- `GET /health`
- `GET /api/analytics/revenue-forecast?days=30`
- `GET /api/analytics/reorder-signals`
- `GET /api/analytics/cohort-retention`
- `GET /api/analytics/dashboard-charts`
