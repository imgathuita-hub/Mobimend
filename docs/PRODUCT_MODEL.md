# Mobimend Product Model

## Problem To Solve

Phone repair customers often cannot tell whether they need a screen, battery, charging port, board repair, software service, or just a compatible accessory. Repair shops also lose time checking part availability, quoting manually, and updating customers one message at a time.

Mobimend can solve this by becoming a repair commerce assistant: it matches a customer's device and symptoms to likely repair services, checks matching parts in inventory, estimates price and turnaround time, books the appointment, collects payment, and keeps the customer updated.

## Core Model

The platform should connect four things that are usually separated:

- Device: brand, model, generation, and known failure patterns.
- Issue: customer symptoms, technician diagnosis, and service category.
- Part: compatible inventory item, quality grade, stock, retail price, wholesale price.
- Outcome: booking, order, payment, repair status, warranty, and customer education.

## Customer Flow

1. Customer chooses device brand and model.
2. Customer selects symptoms such as broken screen, battery drain, no charging, water damage, speaker issue, or software lock.
3. System suggests likely repair services and parts needed.
4. System shows estimated price, duration, warranty, and stock confidence.
5. Customer books a slot or buys the part/accessory.
6. Customer pays by M-Pesa STK Push, bank transfer, or in-store method.
7. Customer tracks status from `Pending` to `Diagnosing`, `Awaiting Parts`, `In Repair`, `Ready`, and `Completed`.

## Admin Flow

Admin sees one workbench instead of scattered records:

- New repair bookings needing confirmation.
- Parts needed for upcoming bookings.
- Low-stock items that block repairs.
- M-Pesa payments waiting for callback confirmation.
- Bank transfer uploads requiring manual verification.
- Wholesale applications needing approval.
- Blog topics suggested by common customer issues.

## Data Intelligence Layer

Start simple with the `device_knowledge_base` table in `schema.sql`. Each record links:

- device brand and model,
- common issue,
- symptoms,
- likely parts,
- recommended repair service,
- customer-facing advice,
- confidence score.

Later, Python can improve this layer by scoring repairs from historic data:

- Predict likely fault from symptoms.
- Recommend parts to reorder based on repair demand.
- Detect high-return or low-margin accessory lines.
- Suggest blog posts from frequent search terms and failed bookings.
- Estimate technician workload and appointment capacity.

## Minimum Valuable Version

Build in this order:

1. Repair booking with device, issue, date/time slot, and status tracking.
2. Inventory-backed wholesale page with MOQ and stock deduction.
3. Retail accessories catalog, cart, checkout, and order tracking.
4. M-Pesa STK Push and callback recording.
5. Admin order, repair, payment, and inventory dashboards.
6. Blog and customer account features.
7. Diagnostic recommendation engine using the knowledge base.

## Key Differentiator

The strongest business angle is not just "repair booking" or "accessories shop." It is a compatibility and availability engine for phone repair: customers get useful answers before they book, and the shop knows whether the repair can actually be completed with available parts.
