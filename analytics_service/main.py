from __future__ import annotations

import math
import os
from datetime import date, datetime
from decimal import Decimal
from typing import Any

import pandas as pd
from fastapi import FastAPI
from sqlalchemy import create_engine, text
from sqlalchemy.engine import Engine


app = FastAPI(title="Mobimend Analytics", version="1.0.0")


def database_url() -> str:
    url = os.getenv("DATABASE_URL", "").strip()
    if not url:
        raise RuntimeError("DATABASE_URL is required")
    return url


def engine() -> Engine:
    if not hasattr(app.state, "engine"):
        app.state.engine = create_engine(database_url(), pool_pre_ping=True, pool_recycle=1800)
    return app.state.engine


def clean_value(value: Any) -> Any:
    if value is None:
        return None
    if isinstance(value, float) and (math.isnan(value) or math.isinf(value)):
        return None
    if isinstance(value, Decimal):
        return float(value)
    if isinstance(value, (pd.Timestamp, datetime, date)):
        return value.isoformat()
    return value


def records(df: pd.DataFrame) -> list[dict[str, Any]]:
    return [{str(key): clean_value(value) for key, value in row.items()} for row in df.to_dict(orient="records")]


@app.get("/health")
def health() -> dict[str, str]:
    with engine().connect() as connection:
        connection.execute(text("SELECT 1"))
    return {"status": "ok"}


@app.get("/api/analytics/revenue-forecast")
def revenue_forecast(days: int = 30) -> dict[str, Any]:
    days = max(1, min(days, 120))
    df = pd.read_sql(
        """
        SELECT DATE(COALESCE(verified_at, updated_at, created_at)) AS day,
               SUM(amount) AS revenue,
               COUNT(*) AS transactions
        FROM payments
        WHERE status = 'paid'
          AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
        GROUP BY DATE(COALESCE(verified_at, updated_at, created_at))
        ORDER BY day
        """,
        engine(),
    )

    if df.empty:
        return {
            "historical": [],
            "forecast_daily_avg": 0.0,
            "forecast_total": 0.0,
            "forecast_30d_total": 0.0,
            "days": days,
            "confidence": "low",
        }

    df["revenue"] = pd.to_numeric(df["revenue"], errors="coerce").fillna(0.0)
    df["transactions"] = pd.to_numeric(df["transactions"], errors="coerce").fillna(0).astype(int)
    df["rolling_avg"] = df["revenue"].rolling(7, min_periods=1).mean()
    forecast = float(df["rolling_avg"].tail(7).mean())

    return {
        "historical": records(df.tail(30)),
        "forecast_daily_avg": round(forecast, 2),
        "forecast_total": round(forecast * days, 2),
        "forecast_30d_total": round(forecast * 30, 2),
        "days": days,
        "confidence": "medium" if len(df) >= 14 else "low",
    }


@app.get("/api/analytics/reorder-signals")
def reorder_signals() -> list[dict[str, Any]]:
    df = pd.read_sql(
        """
        SELECT ii.id, ii.brand, ii.model, ii.part_type,
               ii.quantity, ii.reorder_point,
               COUNT(sm.id) AS sales_last_30d,
               COALESCE(SUM(ABS(sm.quantity_delta)), 0) AS units_sold_30d
        FROM inventory_items ii
        LEFT JOIN stock_movements sm ON sm.inventory_item_id = ii.id
            AND sm.movement_type = 'fulfill'
            AND sm.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY ii.id, ii.brand, ii.model, ii.part_type, ii.quantity, ii.reorder_point
        HAVING units_sold_30d > 0
        """,
        engine(),
    )

    if df.empty:
        return []

    df["quantity"] = pd.to_numeric(df["quantity"], errors="coerce").fillna(0.0)
    df["units_sold_30d"] = pd.to_numeric(df["units_sold_30d"], errors="coerce").fillna(0.0)
    df["daily_velocity"] = df["units_sold_30d"] / 30
    df["days_until_stockout"] = df.apply(
        lambda row: row["quantity"] / row["daily_velocity"] if row["daily_velocity"] > 0 else 999,
        axis=1,
    )
    df["urgency"] = pd.cut(
        df["days_until_stockout"],
        bins=[-0.1, 7, 14, 30, 999999],
        labels=["critical", "high", "medium", "ok"],
    ).astype(str)

    return records(df.sort_values("days_until_stockout").head(20))


@app.get("/api/analytics/cohort-retention")
def cohort_retention() -> dict[str, list[dict[str, Any]]]:
    df = pd.read_sql(
        """
        SELECT u.id, u.created_at AS joined,
               COUNT(DISTINCT o.id) AS orders,
               MIN(o.created_at) AS first_order,
               MAX(o.created_at) AS last_order,
               COALESCE(SUM(o.grand_total), 0) AS lifetime_value
        FROM users u
        LEFT JOIN orders o ON o.user_id = u.id OR o.customer_email = u.email
        WHERE u.role = 'customer'
        GROUP BY u.id, u.created_at
        ORDER BY lifetime_value DESC, orders DESC
        LIMIT 100
        """,
        engine(),
    )
    return {"cohorts": records(df)}


@app.get("/api/analytics/dashboard-charts")
def dashboard_charts() -> dict[str, Any]:
    revenue = pd.read_sql(
        """
        SELECT DATE(COALESCE(verified_at, updated_at, created_at)) AS metric_day,
               COALESCE(SUM(amount), 0) AS total
        FROM payments
        WHERE status = 'paid'
          AND COALESCE(verified_at, updated_at, created_at) >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
        GROUP BY DATE(COALESCE(verified_at, updated_at, created_at))
        """,
        engine(),
    )
    booked = pd.read_sql(
        """
        SELECT DATE(created_at) AS metric_day, COUNT(*) AS total
        FROM repair_bookings
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
        GROUP BY DATE(created_at)
        """,
        engine(),
    )
    completed = pd.read_sql(
        """
        SELECT DATE(updated_at) AS metric_day, COUNT(*) AS total
        FROM repair_bookings
        WHERE status IN ('Completed', 'completed')
          AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
        GROUP BY DATE(updated_at)
        """,
        engine(),
    )
    payments = pd.read_sql(
        """
        SELECT status, COUNT(*) AS total
        FROM payments
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY status
        ORDER BY total DESC
        """,
        engine(),
    )
    orders = pd.read_sql(
        """
        SELECT status, COUNT(*) AS total
        FROM orders
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY status
        ORDER BY total DESC
        """,
        engine(),
    )
    inventory = pd.read_sql(
        """
        SELECT part_type, SUM(quantity) AS total
        FROM inventory_items
        GROUP BY part_type
        ORDER BY total ASC
        LIMIT 8
        """,
        engine(),
    )
    movers = pd.read_sql(
        """
        SELECT brand, model, part_type,
               COALESCE(SUM(quantity), 0) AS units,
               COALESCE(SUM(total_revenue), 0) AS revenue,
               COALESCE(SUM(profit), 0) AS profit
        FROM inventory_transactions
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY brand, model, part_type
        ORDER BY units DESC, revenue DESC
        LIMIT 6
        """,
        engine(),
    )
    recent = pd.read_sql(
        """
        (SELECT 'payment' AS signal_type, CONCAT(payment_method, ' ', status) AS title, amount AS value, created_at
         FROM payments
         ORDER BY created_at DESC
         LIMIT 4)
        UNION ALL
        (SELECT 'repair' AS signal_type, CONCAT(device_model, ' ', repair_type) AS title, estimated_price AS value, created_at
         FROM repair_bookings
         ORDER BY created_at DESC
         LIMIT 4)
        ORDER BY created_at DESC
        LIMIT 6
        """,
        engine(),
    )

    buckets: dict[str, dict[str, Any]] = {}
    today = pd.Timestamp.today().normalize()
    for offset in range(13, -1, -1):
        day = today - pd.Timedelta(days=offset)
        key = day.strftime("%Y-%m-%d")
        buckets[key] = {
            "label": day.strftime("%b %-d") if os.name != "nt" else day.strftime("%b %#d"),
            "revenue": 0.0,
            "booked": 0,
            "completed": 0,
        }

    def fill_bucket(df: pd.DataFrame, field: str) -> None:
        for row in records(df):
            key = str(row.get("metric_day"))
            if key in buckets:
                buckets[key][field] = row.get("total") or 0

    fill_bucket(revenue, "revenue")
    fill_bucket(booked, "booked")
    fill_bucket(completed, "completed")

    return {
        "chart_data": {
            "labels": [bucket["label"] for bucket in buckets.values()],
            "revenue": [bucket["revenue"] for bucket in buckets.values()],
            "repairsBooked": [bucket["booked"] for bucket in buckets.values()],
            "repairsCompleted": [bucket["completed"] for bucket in buckets.values()],
            "paymentLabels": [str(row["status"]).replace("_", " ").title() for row in records(payments)],
            "paymentValues": [row["total"] for row in records(payments)],
            "orderLabels": [str(row["status"]).title() for row in records(orders)],
            "orderValues": [row["total"] for row in records(orders)],
            "inventoryLabels": [row["part_type"] for row in records(inventory)],
            "inventoryValues": [row["total"] for row in records(inventory)],
        },
        "top_movers": records(movers),
        "recent_signals": records(recent),
    }
