# Group Business Operations Platform: System Design Plan

**Status:** Draft v0.4
**Date:** 2026-11-20
**Demo data:** see [DEMO_DATA.md](DEMO_DATA.md)
**Scope:** Restaurant branches (5–7), Catering, Central Kitchen, Branch Kitchens, Warehouse, Purchase, HR & Payroll, POS, Accounting, Management
**Delivery:** **Web app** (back office, POS, HR, Accounting, Admin) + **Mobile app** (field staff, managers, top management) + API backend

---

## 0. Decisions log

| Topic | Decision |
|---|---|
| **Smartphones** *(v0.4)* | **All staff have a smartphone** and check in themselves. |
| **Proxy check-in** *(v0.4)* | Exception only: a manager can allow a specific staff member to check in a **fixed list** of other staff (e.g. max 2) from the manager's own team. See §8.3.1. |
| **Gradual rollout** | Nothing is launched all at once. **Super Admin turns on features one by one** (whole company, or a pilot branch first). A feature that is off is invisible everywhere. |
| **Admin levels** | **Super Admin** has every permission and controls feature activation. **Company Admin** (company management) gets the admin features: users, roles, permissions, approval rules and limits, *within the features Super Admin has turned on*. |
| **Permissions** | Which role can do what is set by the admins, not hard-coded. |
| **Requisitions** | Raised by **Branch Managers** and other permitted manager roles, **not** by waiters or general staff. |
| **Attendance** | **GPS + a "Start / Check-in" button.** When someone checks in at the branch, their **team lead / manager is notified**. |
| **Payment methods** | **Cash, Bank, MFS** (bKash / Nagad / Rocket, etc.), and Card at POS. All options are available everywhere money moves. |
| **Platforms** | **POS, Accounting and HR are web-only.** Top management sees their reports and status on mobile. |
| **POS & Accounting** | We build **our own**. No migration from the old systems. |
| **Legal / company setup** | Skipped for now. |

---

## 1. What we are building

One platform where **every task, shift, request, stock movement, sale and taka in the group is visible, assigned, timed and tracked**, from the owner down to a kitchen helper.

### Guiding principles

1. **Show each person only what they need.** A worker sees tasks, shift and attendance, nothing else.
2. **Turn on gradually.** Each feature is switched on by Super Admin when the business is ready, first for a pilot location if wanted.
3. **Web for desk work, mobile for floor work.** Heavy screens (POS, payroll, accounting, roster building, setup) live on the web. Quick actions (check in, do a task, approve, receive) live on the phone.
4. **Configure, don't hard-code.** Roles, permissions, approval levels, money limits, cut-off times and KPI weights are all settings.
5. **Every action leaves a trail.** Who, what, when, where.

### The engines everything is built on

| Engine | What it answers |
|---|---|
| **Access & Feature Control** | Is this feature on? Is it on at this location? Does this person's role allow it? |
| **Task Engine** | Who must do what, by when, and was it done on time? |
| **Requisition & Approval Engine** | Who asked for what, who approved, who is fulfilling it, where is it now? |
| **Inventory Ledger** | What do we have, where, and what moved in or out and why? |
| **Money Ledger** | Where did every taka come from and go to (cash, bank, MFS)? Modules post here automatically. |

---

## 2. Understanding the business

### 2.1 Units in the group

```
                         ┌──────────────────────┐
                         │  OWNER / MANAGEMENT  │
                         └──────────┬───────────┘
   ┌────────────┬───────────┬───────┴────┬────────────┬────────────┬────────────┬────────────┐
   ▼            ▼           ▼            ▼            ▼            ▼            ▼            ▼
┌────────┐ ┌─────────┐ ┌─────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌─────────┐
│Branches│ │Catering │ │ Central │ │Warehouse │ │ Purchase │ │    HR    │ │ Accounts │ │ Maint./ │
│ (5–7)  │ │         │ │ Kitchen │ │(Raw Mat.)│ │          │ │          │ │          │ │ Admin   │
│├ FOH   │ │├ Sales  │ │├ Prod.  │ │├ Store   │ │├ Manager │ │├ Attend. │ │├ Ledger  │ │         │
│├ POS   │ │├ Events │ │├ Prep   │ │├ Receive │ │├ Buyers  │ │├ Shifts  │ │├ Payables│ │         │
│├Kitchen│ │└ Service│ │└Dispatch│ │└ Issue   │ │└ Vendors │ │└ Payroll │ │└ Reports │ │         │
│└ Store │ │         │ │         │ │          │ │          │ │          │ │          │ │         │
└────────┘ └─────────┘ └─────────┘ └──────────┘ └──────────┘ └──────────┘ └──────────┘ └─────────┘
```

Every unit is a **Location** with stock, people, cash, a GPS geofence, and the ability to send or receive requests. Each location is made up of **Departments**. Each location is also a **cost centre**, so the owner gets a profit and loss report per branch.

### 2.2 How goods and money flow

```
 Vendors ──(Purchase)──► WAREHOUSE ──(raw material)──► CENTRAL KITCHEN ──(cooked / semi food)──► BRANCHES ──► POS sale ──► Customer
    ▲                        │                               │                                       ▲
    │ vendor payment         │                               └──────(catering food)──► CATERING ──► Event invoice
    │ (cash/bank/MFS)        └─────────(raw material)──► BRANCH KITCHENS (cook locally) ─────────────┘
```

* **Central-cooked items:** the branch orders, the central kitchen (CK) produces and dispatches.
* **Branch-cooked items:** the branch kitchen requests raw material from the warehouse and cooks locally.
* Each menu item stores **where it is produced** (central, branch or both), so orders are routed automatically.

### 2.3 The chain reaction (the key scenario)

> Branch A Manager orders 20 kg Chicken Biryani for 11:00 tomorrow.

```
Branch A Manager            Central Kitchen             Warehouse                  Purchase Dept           Buyer
     │ Food order                │                          │                           │                     │
     │ 20kg Biryani @11:00 ────► │                          │                           │                     │
     │                           │ Head Chef accepts.       │                           │                     │
     │                           │ Recipe needs 8kg rice,   │                           │                     │
     │                           │ 10kg chicken…            │                           │                     │
     │                           │ Raw-material request ──► │ Rice ✓ Spices ✓           │                     │
     │                           │                          │ Chicken: only 4kg ✗       │                     │
     │                           │                          │ Purchase request 6kg ───► │ Approves (in limit) │
     │                           │                          │                           │ Assigns buyer,      │
     │                           │                          │                           │ deadline 1 hour,    │
     │                           │                          │                           │ pay: cash advance ─►│ 🔔 Notified
     │                           │                          │ ◄──── Goods received ─────┼─────────────────────┤ Buys, uploads
     │                           │ ◄── Issues 10kg chicken ─┤ (stock +6)                │                     │ receipt
     │                           │ Production → Dispatch    │                           │                     │
     │ ◄──── Delivered 10:40 ────┤                          │                           │                     │
     │ Confirms receipt (20kg)   │                          │                           │                     │
```

Every request is **linked to the one that caused it**, so the Branch A Manager can see:
*"Waiting: Central Kitchen → Warehouse → Buying chicken (buyer: Rahim, due 09:30)."*

---

## 3. Access & Control Model

### 3.1 Three admin levels

```
┌───────────────────────────────────────────────────────────────────────────────┐
│ SUPER ADMIN  (system owner, e.g. you or the IT team; very few people)          │
│ • ALL permissions                                                              │
│ • Turns FEATURES on/off (company-wide or per location)                         │
│ • Creates Company Admins                                                       │
│ • System settings: SMS/push gateways, MFS list, backups, security policy       │
│ • Sees the full audit log, including admin actions                             │
└───────────────────────────────┬───────────────────────────────────────────────┘
                                ▼
┌───────────────────────────────────────────────────────────────────────────────┐
│ COMPANY ADMIN  (company management)                                            │
│ • Works only inside features that Super Admin has turned ON                    │
│ • Locations, departments, employees, teams                                     │
│ • Roles & permissions (who can do what)                                        │
│ • Approval matrix & personal money limits                                      │
│ • Master data (items, menu, recipes, vendors), business settings               │
│ • Cannot turn features on or off                                               │
└───────────────────────────────┬───────────────────────────────────────────────┘
                                ▼
┌───────────────────────────────────────────────────────────────────────────────┐
│ ROLES  (Owner/GM, Branch Manager, Head Chef, Supervisor, Worker, Buyer, …)     │
│ • Get permissions from Company Admin                                           │
│ • Limited to their scope (own location / department / team)                    │
└───────────────────────────────────────────────────────────────────────────────┘
```

### 3.2 The four gates

A person sees a button or screen **only if all four gates say yes**:

```
 1. FEATURE ON?        (Super Admin: is the feature activated?)
 2. ON AT LOCATION?    (Super Admin: is it on for this branch, e.g. pilot?)
 3. ROLE ALLOWED?      (Company Admin: does the role have the permission?)
 4. IN SCOPE / LIMIT?  (own location, department or team; amount within the personal limit)
```

If any gate says no, the item **does not appear**: no greyed-out menus, no confusing extra options.

### 3.3 Default role templates

Company Admin can change any of these. They are starting points only.

| Role | Platform | Default permissions |
|---|---|---|
| **Owner / GM** | Mobile + Web | All reports and dashboards, approvals above limits, assign tasks to anyone |
| **Branch Manager** | Mobile (+ Web) | **Raise requisitions & food orders**, approve within limit, assign tasks, team attendance & roster, receive deliveries, branch dashboard |
| **Head Chef (CK / Branch)** | Mobile (+ Web) | **Raise raw-material requisitions**, accept food orders (CK), production plan, assign kitchen tasks |
| **Warehouse Manager** | Web + Mobile | Accept/issue requisitions, receive goods, stock, **raise purchase requests** |
| **Purchase Manager** | Web + Mobile | Approve purchases, assign buyers with deadlines, vendors |
| **Catering Manager** | Web + Mobile | Events, **raise catering requisitions**, assign event staff |
| **HR Manager / Officer** | Web | Employees, roster, attendance rules, leave, payroll |
| **Accounts** | Web | Accounting, payments, settlements |
| **Cashier** | Web (POS) | POS only |
| **Supervisor / Team Lead** | Mobile | Assign tasks to own team, team attendance (gets arrival alerts) |
| **Buyer** | Mobile | My purchases, My wallet |
| **Driver** | Mobile | My deliveries |
| **Storekeeper** | Mobile (+ Web) | Issue / receive stock, stock counts |
| **Worker** (cook, helper, waiter, cleaner) | Mobile | **Only:** my tasks, my shift, my attendance, my profile |

> **Requisitions are a manager-level permission.** Waiters and general staff cannot raise requisitions. If a worker needs something, they tell their supervisor or manager, who raises it. Company Admin can grant the permission to other roles (e.g. a Supervisor) if needed.

---

## 4. Feature Activation (one-by-one rollout)

### 4.1 How it works

* All features are **built into the system** but start **OFF**.
* Super Admin opens **Feature Control** (web) and switches a feature:
  `OFF → PILOT (selected locations) → ON (all locations)`
* **Dependencies are enforced:** a feature can't be turned on before the features it needs (e.g. Payroll needs Attendance + Shifts).
* **Setup checklist before activation:** the system shows what must be ready first (e.g. *Purchase* needs vendors, items and the approval matrix to be configured).
* **Turning a feature off** hides it but **keeps all data**. Turning it on again restores everything.
* Every activation change is audited (who, when, which locations).

### 4.2 Feature Control screen (web, Super Admin)

```
┌────────────────────────────────────────────────────────────────────────────┐
│ Feature Control                                                            │
├────────────────────────────┬──────────┬──────────────────────┬─────────────┤
│ Feature                    │ Status   │ Locations            │ Needs       │
├────────────────────────────┼──────────┼──────────────────────┼─────────────┤
│ Tasks: basic               │ ● ON     │ All                  │ –           │
│ Tasks: recurring checklist │ ◐ PILOT  │ Branch A, CK         │ Tasks basic │
│ Attendance: GPS check-in   │ ● ON     │ All                  │ Shifts      │
│ Attendance: arrival alert  │ ● ON     │ All                  │ Attendance  │
│ Requisitions: food order   │ ○ OFF    │ –                    │ Items, CK   │
│ Purchase: vendor credit    │ ○ OFF    │ –                    │ Purchase    │
│ POS                        │ ○ OFF    │ –   [Setup 3/6 ✓]    │ Menu, Inv.  │
└────────────────────────────┴──────────┴──────────────────────┴─────────────┘
```

### 4.3 Feature catalogue

| Module | Feature keys (each can be switched separately) | Depends on |
|---|---|---|
| **Core** *(always on)* | users, roles & permissions, locations, notifications, language, audit | – |
| **Tasks** | `tasks.basic`, `tasks.team`, `tasks.proof_photo`, `tasks.recurring`, `tasks.escalation` | – |
| **Shifts** | `shifts.roster`, `shifts.swap` | – |
| **Attendance** | `attendance.gps_checkin`, `attendance.arrival_alert`, `attendance.no_show_alert`, `attendance.correction`, `attendance.remote_checkin`, `attendance.proxy_checkin`, `attendance.overtime` | shifts.roster |
| **Leave** | `leave.request`, `leave.balance` | attendance |
| **KPI** | `kpi.scorecard`, `kpi.payroll_link` | tasks, attendance |
| **Payroll** | `payroll.run`, `payroll.payslip_mobile`, `payroll.advance_loan` | attendance, shifts |
| **Inventory** | `inventory.stock`, `inventory.batch_expiry`, `inventory.stock_count`, `inventory.wastage`, `inventory.auto_reorder` | items |
| **Requisitions** | `req.supplies`, `req.raw_material`, `req.food_order`, `req.standing_order`, `req.equipment`, `req.repair`, `req.transfer` | inventory.stock |
| **Purchase** | `purchase.core`, `purchase.cash_advance`, `purchase.petty_cash`, `purchase.reimbursement`, `purchase.vendor_credit`, `purchase.price_check` | inventory, approval matrix |
| **Production** | `production.recipes`, `production.plan`, `production.auto_raw_material` | req.food_order |
| **Dispatch** | `dispatch.delivery`, `dispatch.receiver_confirm` | requisitions |
| **Catering** | `catering.events`, `catering.auto_requisitions`, `catering.invoices` | requisitions |
| **POS** | `pos.core`, `pos.tables`, `pos.kot`, `pos.kds`, `pos.delivery_orders`, `pos.recipe_deduction` | menu, inventory |
| **Accounting** | `acc.core`, `acc.auto_posting`, `acc.payables`, `acc.receivables`, `acc.bank_mfs_reconcile`, `acc.period_close` | – |
| **Mgmt Mobile** | `mgmt.mobile_dashboard`, `mgmt.mobile_reports` | (shows only data from activated modules) |

---

## 5. Platforms: Web vs Mobile

### 5.1 Module × platform

| Module | Web | Mobile |
|---|---|---|
| **Admin / Feature Control / Config** | ✅ Full | ❌ |
| **Tasks** | ✅ Create, boards, templates, reports | ✅ Daily use: do tasks, assign to team, approve proof |
| **Attendance** | ✅ HR: rules, geofences, corrections, reports | ✅ Check in/out (everyone); team live board + arrival alerts (leads/managers) |
| **Shifts / Roster** | ✅ Build roster (HR / managers) | ✅ View my shifts, swap request; managers view team roster |
| **Leave** | ✅ HR admin | ✅ Apply and approve |
| **Payroll** | ✅ **Web only** | 👁 Worker: own payslip (if enabled) · Top mgmt: payroll summary |
| **KPI** | ✅ Config & reports | ✅ My score · team score · top mgmt summary |
| **Requisitions** | ✅ Full | ✅ Raise (permitted managers), approve, accept, receive, track |
| **Inventory / Warehouse** | ✅ Full | ✅ Storekeeper: issue/receive, stock lookup, count |
| **Purchase** | ✅ Full | ✅ Purchase Mgr: approve and assign · Buyer: my purchases & wallet |
| **Production (CK)** | ✅ Plan & recipes | ✅ Chef: today's plan, mark done |
| **Dispatch** | ✅ Plan | ✅ Driver: deliveries · Receiver: confirm |
| **Catering** | ✅ Full | ✅ Catering Mgr: events & status |
| **POS** | ✅ **Web only** (counter PC / tablet browser) | 👁 Top mgmt: live sales |
| **Accounting** | ✅ **Web only** | 👁 Top mgmt: cash, bank and MFS balances, dues, P&L summary |
| **HR records** | ✅ **Web only** | 👁 Top mgmt: headcount, attendance today |

👁 = read-only reports / status

### 5.2 Mobile app by person type

**Worker (T1)**: four tabs, nothing else
```
┌──────────────────────────────┐
│  আজ / Today      🔔  EN|বাং  │
├──────────────────────────────┤
│  Shift: 10:00 – 18:00        │
│  Branch A · Kitchen          │
│  📍 You are at Branch A      │
│  [   ▶ START / CHECK IN   ]  │
├──────────────────────────────┤
│  My tasks today (4)          │
│  ○ Clean fryer      by 11:00 │
│  ○ Cut onions 5kg   by 11:30 │
│  ● Prep salad       done ✓   │
│  ⚠ Mop floor        OVERDUE  │
├──────────────────────────────┤
│ [Today] [Tasks] [Attendance] [Me] │
└──────────────────────────────┘
```

**Supervisor / Team Lead (T2)**: Worker tabs + **My Team** (who's in, team tasks, assign task).

**Manager (T3)**: + **Approvals**, **Requests** (raise and track), **Dept queue**, **Location dashboard**.

**Function roles**: Worker tabs + one focused tab: Buyer → *Purchases & Wallet*, Driver → *Deliveries*, Storekeeper → *Stock In/Out*.

**Top Management (T4)**: **Management tab** with read-only dashboards and drill-down, plus approvals:
```
┌──────────────────────────────────────┐
│ Group Today                  EN|বাং  │
├──────────────────────────────────────┤
│ Sales today      ৳ 3,42,500  ▲ 8%    │  ← POS
│ Attendance       186/204 in · 7 late │  ← HR
│ Pending approvals          5  [→]    │
│ Open requests    12 (2 delayed)      │
│ Purchase spend   ৳ 48,200            │
│ Cash ৳… · Bank ৳… · MFS ৳…           │  ← Accounting
│ Payables due this week  ৳ 1,25,000   │
│ Overdue tasks    9 (Branch C: 5)     │
│ Catering: 3 events this week         │
├──────────────────────────────────────┤
│ Reports: Sales · P&L · Payroll ·     │
│ Attendance · KPI · Stock · Wastage   │
└──────────────────────────────────────┘
```
Each card appears only when its module is activated.

### 5.3 Web app workspaces

Each web user lands in their own workspace with only their menus:
**Super Admin** (Feature Control, system) · **Company Admin** (setup) · **HR** · **Accounts** · **POS** (full-screen cashier mode) · **Warehouse** · **Purchase** · **Central Kitchen** · **Catering** · **Management** (reports) · **Branch Manager** (optional desktop view).

---

## 6. Company Admin Configuration (web)

### 6.1 Roles & permissions
A **Role × Permission** grid, limited to activated features. Example permissions: `req.food_order.create`, `req.approve`, `task.assign.team`, `attendance.team.view`, `purchase.assign_buyer`, `pos.discount`, `pos.void`, `report.pnl.view`.

### 6.2 Approval matrix
For each **request type** × **location (or all)** × **amount range** → ordered **approval levels** (a role, e.g. "Branch Manager of the requesting branch", or a specific person).

| Request type | Location | Amount (BDT) | Level 1 | Level 2 | Level 3 |
|---|---|---|---|---|---|
| Purchase | Any | 0 – 5,000 | Purchase Mgr | – | – |
| Purchase | Any | 5,001 – 50,000 | Purchase Mgr | GM | – |
| Purchase | Any | > 50,000 | Purchase Mgr | GM | Owner |
| Food order | Branch A | any | (auto: raised by Br. Mgr) | – | – |
| Leave | Any | – | Direct manager | HR | – |

*(Example values only.)* Options: sequential or parallel, auto-approve below X, SLA per level with escalation, and skip a level if the requester is that approver.

### 6.3 Personal limits
Approval limit · purchase spend limit · cash advance limit · petty cash float · own-money (reimbursement) limit · POS discount limit.

### 6.4 Delegation
Approver on leave → auto-delegate to a backup for those dates, recorded as "approved by X on behalf of Y".

### 6.5 Business settings
Order cut-off times, geofence radius, attendance grace minutes, receipt upload deadline, KPI weights, VAT and service-charge rates, notification rules, payment methods (from the Super Admin list), Bangla and English labels.

---

## 7. Operational modules

### 7.1 Task Management
* **Assign** to anyone below you in the reporting tree, or to a **team** you manage.
* **Fields:** title (EN/BN), details, assignee(s) or team, priority, start, **due time**, checklist, proof (photo or note), recurrence, linked item.
* **Types:** one-off · **recurring checklist** (auto-assigned to whoever is **on shift**) · team task · system task (e.g. "Prepare 20 kg biryani", "Buy 6 kg chicken").
* **Status:** `ASSIGNED → STARTED → SUBMITTED (proof) → APPROVED → DONE`, with `REWORK` looping back. Past due → `OVERDUE` → escalate to the assigner, then their manager.
* Records assigned, started, completed and due times plus rework count, which **feed KPI**.

### 7.2 Requisition Engine
**Who can raise:** only roles with permission (default: Branch Manager, Head Chef, Warehouse Manager, Purchase Manager, Catering Manager, department heads).

```
DRAFT → PENDING_APPROVAL (approval matrix) ──► REJECTED (reason)
           ▼
        SENT_TO_FULFILLER → ACCEPTED | PARTIALLY_ACCEPTED (requester confirms) | REJECTED
           ▼
        IN_PROGRESS (may create linked child requests) → DISPATCHED → RECEIVED (qty per line,
        shortage/damage photo) → CLOSED          Side states: CANCELLED, ON_HOLD
```

| Type | Raised by (default) | Fulfilled by |
|---|---|---|
| Food order | Branch Manager, Catering Manager | Central Kitchen |
| Raw material | Head Chef (CK / branch), Catering Manager | Warehouse |
| Supplies | Branch Manager, department heads | Warehouse |
| Equipment / asset | Branch Manager, department heads | Warehouse or Purchase |
| Repair | Branch Manager, department heads | Maintenance |
| Purchase | Warehouse Manager, department heads | Purchase → Buyer |
| Stock transfer | Branch Manager | Other branch |

### 7.3 Branch Ordering: fixed + ad-hoc
* **Fixed (standing) orders:** a par list per weekday. It is auto-created every night, the Branch Manager can edit it until the **cut-off** (e.g. 20:00), and then it's locked into the CK plan. Can be paused for holidays.
* **Ad-hoc orders:** any time. After the cut-off they are marked **URGENT**, and the CK must accept or reject explicitly.
* The CK sees one **combined plan**: fixed + ad-hoc + catering.

### 7.4 Inventory / Warehouse
Item master (EN/BN) · unit conversions (sack → kg → g) · stock per location kept as a **movement ledger** (purchase, issue, transfer, production, POS sale, wastage, adjustment, return) · reorder levels and alerts (optional auto purchase request) · batch and expiry with first-expiry-first-out · stock count tasks with variance · wastage log with photo.

### 7.5 Purchase & Buyer Accountability

```
Purchase Request ← manual | auto (low stock) | shortage from a requisition
   ▼ approval matrix (by amount)
Purchase Manager: vendor, estimate, FUNDING TYPE, PAYMENT METHOD, ASSIGN BUYER + DEADLINE (1h / 1 day / custom)
   ▼ 🔔 buyer notified (push + SMS)
Buyer: ACCEPT → BUYING → actual qty/price, vendor, RECEIPT PHOTO → DELIVERED
   ▼
Warehouse: goods received (check qty/quality) → stock +
   ▼
Accounts: settle → automatic money-ledger entries
```

**Funding types** (each can be activated separately):

| Funding | How it works |
|---|---|
| **Cash advance** | Accounts gives the buyer an advance (cash, bank or MFS) within their limit. Purchases are deducted from it, and leftover is returned or carried forward. |
| **Petty cash** | Paid from the location's petty cash fund. The custodian requests a top-up when it runs low. |
| **Own money → refund** | The buyer pays personally, uploads the receipt, and a refund claim is created automatically → approved → refunded by **cash, bank or MFS**. |
| **Vendor credit** | A vendor bill with a due date is created. Accounts pays later (cash, bank or MFS), fully or in parts. |

**Buyer's "My Wallet" (mobile):** advance held · spent · balance to return · **company owes me** (pending refunds) · receipts pending · active purchases with countdown · full history (amount, funding, method, receipt, approver, settled date).
**Rules:** a receipt is required within X hours, and missing receipts block new advances. A price check compares the paid price with the last price and the vendor's average.

### 7.6 Central Kitchen Production
Recipes (ingredients, yield, cost) · **daily production plan** (fixed + ad-hoc + catering) · auto raw-material calculation (recipe × qty − CK stock) → a draft warehouse request · production orders become cook tasks · on completion, ingredients are used and finished stock is added · dispatch plan by branch.

### 7.7 Branch Kitchen
Branch-cooked items use raw material by recipe (from POS sales once the POS is active). The Branch Manager sees incoming deliveries, open requests, stock and checklists.

### 7.8 Catering
Event booking (client, date, venue, guests, menu, price, advance) → **confirming the event auto-creates** food orders, equipment requests, staff tasks and an event-day checklist. Event calendar for CK load. Customer dues and collections (cash, bank or MFS). Equipment return check. *A catering event can create a **temporary geofence** at the venue so event staff can check in there.*

### 7.9 Dispatch & Delivery
Delivery note (items, qty, from, to, driver, vehicle) · driver stop list · picked up → delivered with **receiver confirmation** (PIN or tap) · live status on the original request.

---

## 8. HR Department (web), with staff features on mobile

### 8.1 Employee records (web)
Profile (EN/BN name, photo, NID, phone, address, emergency contact), joining date, location, department, position, manager, type (permanent, probation, part-time, daily wage), salary structure, **payout method** (cash, bank or MFS, with account / MFS number visible only to HR and Accounts), documents, and history.

### 8.2 Shifts & Roster
Shift templates per location (morning, evening, **split shift**) · weekly roster built on the web (drag and drop, with warnings for clashes, under-staffing and too many hours) · weekly off days · shift swap (worker ↔ worker → manager approves) · roster published to phones · recurring tasks go to whoever is on shift.

### 8.3 Attendance: GPS + Start button + arrival alert

```
Worker opens app near shift time
   ▼
App reads GPS ──► inside location geofence?  ── NO ──► "You are 350 m from Branch A" (button disabled)
   │ YES                                              (optional: "Request remote check-in" + reason → manager approves)
   ▼
Worker taps  [ ▶ START / CHECK IN ]
   ▼
Server records: time, GPS, accuracy, device · compares with roster → ON TIME / LATE (x min)
   ▼
🔔 Team Lead / Manager notified: "Karim checked in at Branch A · 09:58 · on time"
   ▼
End of shift: [ ■ END / CHECK OUT ] (GPS checked again) → hours & overtime calculated
```

* **Arrival alert:** the team lead and/or manager gets a notification for each check-in. Company Admin chooses per location: *every check-in*, *only late*, or *a summary every 15 min*.
* **No-show alert:** no check-in by shift start + grace → the lead and manager are notified ("Rahim not checked in, shift 10:00") so they can call or arrange a replacement.
* **Team live board** (mobile, leads/managers): who is in, late, not arrived, on leave or off.
* **Anti-cheating without a selfie:** one registered phone per employee (device binding), fake-GPS / mock-location detection, and a GPS accuracy threshold. *(A selfie can be added later as a separate feature if needed.)*
* **Off-site staff:** buyers, drivers and catering staff check in at their base location, at an event's temporary geofence, or by remote check-in with approval.
* Forgot to check out → auto-closed at shift end and flagged for the manager. Missed punches are fixed by a **correction request** (manager approves).
* Automatic status: present, late, early leave, absent, leave, off day, holiday. Grace and late rules set by HR. **Overtime** counts only when the manager approves it.

#### 8.3.1 Proxy check-in (special permission)

Everyone normally checks in on their own phone. Proxy check-in is the exception, for when someone's phone is broken, lost or out of charge.

**Rules**
1. Only a **manager** (Branch Manager, Head Chef, department head) can create a proxy grant, and only for **staff in their own team**.
2. A grant is **fixed**: *Proxy person → a named list of staff*. The proxy can check in **only those people**, nobody else.
3. Maximum people per proxy is set by Company Admin (**default: 2**).
4. The proxy must be **checked in themselves** first, **inside the geofence**, on **their own registered phone**.
5. The grant can be **permanent** (until revoked) or **date-limited** (e.g. this week only). The manager can revoke it at any time.
6. Check-out works the same way: the proxy can check out only the same listed people.

**Flow**
```
Proxy (e.g. Sous Chef Shafiq) opens Attendance → "Check in for my team"
   ▼
Sees ONLY his fixed list:  ☐ Rubel Mia   ☐ Alamgir Hossain
   ▼
Ticks Rubel → [Confirm: Rubel is present with me]
   ▼
Server checks: grant active? Rubel in list? Shafiq checked in + inside geofence + own device?
   ▼
Record saved as: Rubel Mia · 09:57 · ON TIME · method = PROXY · by = Shafiq Ahmed
   ▼
🔔 Manager notified: "Shafiq checked in Rubel Mia (proxy) · 09:57"
🔔 Rubel notified too (if his phone works later): "You were checked in by Shafiq at 09:57"
```

**Controls**
* Proxy records are clearly marked **PROXY** in reports and on the team live board.
* Monthly report: proxy check-ins per person. Too many proxy check-ins for one employee is flagged to HR.
* Optional later feature: the proxy takes a photo of the person being checked in.
* All grants, changes and revokes are in the audit log.

### 8.4 Leave
Leave types and balances (set by HR) · request on mobile → approval matrix → shows on roster and attendance automatically · holiday calendar.

### 8.5 Payroll (web only)

```
Month close
  ├─ Attendance summary (present, absent, late, leave, OT)       ← §8.3
  ├─ Salary structure (basic + allowances)
  ├─ + overtime, + KPI incentive (if linked), + festival bonus, + service-charge share (if used)
  ├─ − absence/late deductions, − salary advance/loan instalments, − other deductions
  ▼
Draft per location → HR review → approval (matrix) → PAY via cash / bank / MFS (per employee's payout method)
  ▼
Payslip (EN/BN PDF) → worker's mobile (if enabled) · salary entries posted to accounting automatically
```
Salary advance and loans with automatic instalments · daily-wage pay from hours · final settlement.
**Bulk payment sheet** per method: a bank list, an MFS list (number + amount), and a cash sheet with signatures.

### 8.6 KPI
Calculated automatically from real data. **Weights are set by Company Admin.**

| Who | Metrics |
|---|---|
| Every worker | Task on-time rate, completion rate, rework rate, attendance rate, punctuality |
| Supervisor / Manager | Team average, approval turnaround, team overdue tasks |
| Central Kitchen | Orders delivered on time and in full |
| Warehouse | Request acceptance time, stock variance, expiry loss |
| Buyer | Purchases on time, price vs benchmark, receipt delay |
| Driver | On-time delivery |
| Branch | Sales vs target, food cost %, wastage % (once POS and inventory are active) |

Example: `Score = 40% task on-time + 20% completion + 10% (1 − rework) + 30% attendance/punctuality`
Workers see **only their own** monthly score. Managers see their team. Optional link to a payroll incentive.

---

## 9. POS (web only, our own)

Runs in the browser on a counter PC or tablet as an **offline-capable web app**: sales continue without internet and sync when it's back.

* **Menu:** categories, items (EN/BN), variants, add-ons, combos, branch prices, availability on or off.
* **Order types:** dine-in (table map), takeaway, own delivery, third-party delivery orders.
* **KOT** to a kitchen printer or **Kitchen Display** screen.
* **Billing:** discounts (within limit), VAT, service charge, split or merge, void or refund (manager PIN + reason, audited).
* **Payments:** **cash, card, MFS (bKash/Nagad/Rocket…), bank**, or mixed. The MFS transaction ID is recorded.
* **Cashier shift:** opening cash → closing count **per payment method** (cash, card, each MFS) → difference reported. Day-end report.
* **Automatic effects:** stock deducted by recipe, sales posted to accounting, branch sales shown on the management mobile dashboard live.

---

## 10. Accounting (web only, our own)

We start fresh, with no migration. At go-live, Accounts enters **opening balances** once (cash, each bank account, each MFS wallet, stock value, vendor dues).

* Chart of accounts (restaurant template, editable) · **cost centres = locations**.
* **Money accounts:** cash boxes, bank accounts, **MFS wallets** (each bKash/Nagad/Rocket number is its own account), petty cash funds, employee advance accounts.
* **Automatic postings** from POS, catering, purchases (by funding type), vendor payments, petty cash, refunds, payroll, wastage and stock adjustments.
* Payables (vendor bills, due dates, part payments) · receivables (catering, corporate) · expenses with approval.
* **Reconciliation** for bank and MFS statements.
* Reports: day book, ledger, trial balance, **P&L per branch / CK / catering / group**, balance sheet, cash flow, food cost %, payroll cost %.
* Month-end close locks the period.

---

## 11. Cross-cutting

### 11.1 Payment methods (used everywhere)
Super Admin defines the list: **Cash · Bank transfer · MFS** (bKash, Nagad, Rocket, Upay… each enabled separately) · **Card** (POS only) · Cheque (optional).
They are used in POS collection, catering collection, vendor payment, buyer advance, refund, petty cash top-up and salary payment. Each transaction records the method, the company account used, and a reference / transaction ID.
*Phase 1: MFS is **recorded** (the transaction ID is entered manually). Direct MFS API integration can be a later feature.*

### 11.2 Notifications & escalation

| Event | Who is notified |
|---|---|
| **Staff checked in at branch** | Team Lead / Manager (per location setting) |
| **Staff not checked in by shift start** | Team Lead / Manager |
| **Proxy check-in done** | Manager + the person checked in |
| Request needs my approval | Approver (escalates after SLA) |
| My request approved, rejected or accepted | Requester |
| New item in my department queue | Department head + on-shift staff |
| Task assigned, due soon or overdue | Assignee → assigner → their manager |
| Buyer assignment with deadline | Buyer (push + SMS) |
| Shift published or changed, swap requests | Worker |
| Delivery dispatched or arrived | Receiver |
| Low stock, expiry soon | Storekeeper, Purchase Manager |
| Cash/MFS difference at cashier close, big wastage, price spike | Manager, GM |
| Payslip ready | Worker |

Push (main) · in-app inbox · SMS for urgent items · off-duty quiet hours · sent in the **user's language** · a notification is sent only if its feature is active.

### 11.3 Bangla + English
One-tap switch (EN | বাং), remembered per user · all UI text in both languages · master data has `name_en` + `name_bn` (falls back to the other if one is missing) · optional Bangla digits · Bangla on payslips, POS bills, KOT and delivery notes.

### 11.4 Audit
Who, what, when, device and GPS (for check-ins), with before and after values. Nothing is hard-deleted. Feature activation, permission changes, voids, discounts, stock adjustments, approvals and payroll edits are always logged.

---

## 12. Data model (core entities)

```
PLATFORM CONTROL
  Feature (key, module, depends_on[]) · FeatureActivation (feature, status OFF|PILOT|ON, locations[], by, at)
  FeatureSetupCheck · SystemSetting · PaymentMethod (type CASH|BANK|MFS|CARD|CHEQUE, provider, active)

ORG & ACCESS
  Location (type, geofence lat/lng/radius, cost_centre) · Department · Position · Team
  Employee (location, dept, position, reports_to, tier, limits{}, payout_method, payout_account, device_id)
  Role · Permission · RolePermission · UserRole (scope) · Delegation

CONFIG
  ApprovalRule (type, location_scope, amount range, mode, levels[], sla) · Setting · Translation

TASKS
  Task (title_en/bn, assignees/team, assigner, start, due, priority, recurrence, proof, linked, status,
        started_at, completed_at, rework_count) · TaskTemplate · TaskComment · TaskProof

REQUISITIONS
  Requisition (type, from/to, requester, needed_by, priority, status, parent_id, source MANUAL|STANDING|AUTO)
  RequisitionLine · ApprovalStep · StatusHistory · StandingOrder (+ lines per weekday)

INVENTORY & PRODUCTION
  Item (name_en/bn, type, unit, conversions, production_rule) · Recipe (+ lines)
  Stock · StockMovement · StockCount · Wastage · ProductionOrder · Dispatch (+ lines, confirmation)

PURCHASE & WALLET
  PurchaseAssignment (buyer, deadline, funding_type, payment_method, status) · PurchaseReceipt
  GoodsReceipt · Vendor · VendorPriceHistory · CashAdvance · PettyCashFund · PettyCashTxn
  ReimbursementClaim · VendorBill · VendorPayment

HR
  ShiftTemplate · Roster · ShiftSwap · AttendanceRecord (in/out time, lat/lng, accuracy, device,
  mock_flag, status, late_min, ot_min, method SELF|PROXY|REMOTE|CORRECTION, by_employee) · RemoteCheckinRequest ·
  ProxyGrant (manager, proxy_employee, staff[], valid_from, valid_to, active) · AttendanceCorrection · ArrivalAlertRule
  LeaveType · LeaveBalance · LeaveRequest · Holiday · SalaryStructure · PayrollRun · Payslip ·
  SalaryAdvance · Loan · KpiDefinition · KpiScore

CATERING   CateringEvent (+ venue geofence) · CateringInvoice · CustomerReceipt
POS        Menu · MenuItem · Table · PosOrder (+ lines) · Kot · PosPayment (method, ref) · CashierShift · Customer
ACCOUNTING Account · MoneyAccount (cash box / bank / MFS wallet) · JournalEntry (+ lines, cost_centre,
           source) · FiscalPeriod · Reconciliation · Expense
COMMON     Notification · AuditLog · Attachment · Device
```

---

## 13. Technical architecture

```
 ┌───────────────────────────────┐            ┌───────────────────────────────┐
 │ WEB APP (Next.js / React)     │            │ MOBILE APP (React Native/Expo)│
 │ • Super Admin: Feature Control│            │ Android + iOS                 │
 │ • Company Admin: setup        │            │ • Worker / Lead / Manager     │
 │ • HR · Payroll · Accounting   │            │ • Buyer / Driver / Storekeeper│
 │ • POS (offline PWA) · KDS     │            │ • Top Mgmt dashboards         │
 │ • Warehouse · Purchase · CK   │            │ • GPS check-in, push          │
 │ • Catering · Reports          │            │                               │
 └───────────────┬───────────────┘            └───────────────┬───────────────┘
                 │   HTTPS REST/JSON · WebSocket (live) · POS sync API         │
                 ▼                                                             ▼
 ┌──────────────────────────────────────────────────────────────────────────────────┐
 │ API BACKEND: modular monolith (NestJS, TypeScript)                                │
 │ Auth (phone + OTP / password, device binding, JWT)                                │
 │ Access gate: Feature activation → Location → Role permission → Scope/limit        │
 │ Modules: Org · Config · Tasks · Requisitions · Inventory · Production · Purchase/ │
 │ Wallet · Catering · Dispatch · HR · Attendance · Payroll · KPI · POS · Accounting │
 │ · Notifications · Reports · Audit                                                 │
 │ Workflow engine (state machines + approval matrix) · Posting engine (auto journals)│
 └──────┬──────────────────┬───────────────────┬──────────────────┬─────────────────┘
        ▼                  ▼                   ▼                  ▼
   PostgreSQL         Redis + job queue    Object storage     FCM push · SMS gateway
                      (SLA timers, no-show  (receipts, photos,
                      checks, recurring      payslip PDFs)
                      tasks, standing orders,
                      payroll, KPI)
```

**Why this stack**
* **TypeScript end-to-end** (web, mobile, backend) in **one monorepo**: shared data types, validation rules, **one shared Bangla/English translation set**, and the **same feature/permission keys** everywhere. One team can work across all parts.
* **The access gate lives in the backend.** The app receives the user's list of *enabled features + permissions* at login and draws only those menus. The backend also rejects any call to a disabled feature, so hiding a menu is never the only protection.
* **Modular monolith:** simpler to build and keeps stock and money consistent. It can be split later if needed.
* **POS as an offline PWA:** local browser database, sync on reconnect. The server owns menu and prices; the device owns the sales it created.
* **Scale:** ~250 users and 7 branches run comfortably on one cloud server + a managed PostgreSQL with daily backups and point-in-time restore.

*(Flutter for mobile is a valid alternative if your developers prefer it. The backend design doesn't change.)*

---

## 14. API outline

```
Auth        POST /auth/otp · /auth/login · /auth/refresh · /auth/device/register
Me          GET /me (profile, tier, ENABLED FEATURES, permissions, language) · /me/home
            /me/tasks · /me/roster · /me/attendance · /me/payslips · /me/kpi · /me/wallet · /me/notifications
SuperAdmin  /features · PUT /features/{key}/activation · GET /features/{key}/setup-check
            /company-admins · /payment-methods · /system-settings · /audit
CompanyAdm  /locations (+ geofence) · /departments · /employees · /teams · /roles · /role-permissions
            /approval-rules · /limits · /delegations · /settings · /translations
Tasks       /tasks · POST /tasks/{id}/start|submit|approve|rework|comment · /task-templates · /tasks/team-board
Attendance  POST /attendance/check-in|check-out (lat, lng, accuracy, device) · GET /attendance/team-live
            /attendance · /attendance/corrections · /attendance/remote-requests · /arrival-alert-rules
            /attendance/proxy-grants (manager CRUD) · GET /me/proxy-list · POST /attendance/proxy-check-in|proxy-check-out
Shifts      /shift-templates · /roster · /shift-swaps
Leave       /leave-types · /leave-requests · /holidays
Requisition /requisitions · POST …/{id}/submit|approve|reject|accept|partial-accept|dispatch|receive|cancel
            GET …/{id}/timeline · /requisitions/inbox · /requisitions/queue · /standing-orders
Inventory   /items · /units · /stock · /stock-movements · /stock-counts · /wastage · /recipes
Production  /production-plans?date= · /production-orders · /dispatches
Purchase    /purchase-assignments · POST …/accept|submit-receipt|delivered · /goods-receipts · /vendors
            /cash-advances · /petty-cash · /reimbursements · /vendor-bills · /vendor-payments
Catering    /catering-events · POST …/confirm · /catering-invoices · /customer-receipts
Payroll     /salary-structures · /payroll-runs · POST …/calculate|approve|pay · /payslips · /advances · /loans
KPI         /kpi-definitions · /kpi-scores?month=
POS         /pos/menu · POST /pos/sync · /pos/cashier-shifts · /pos/reports/day-end
Accounting  /accounts · /money-accounts · /journal-entries · /expenses · /reconciliations · /periods/{id}/close
Mgmt        GET /mgmt/dashboard · /mgmt/reports/{sales|pnl|payroll|attendance|kpi|stock|wastage|purchase|sla}
```

---

## 15. Build plan & activation waves

There are two timelines:
* **Build phases:** what the developers deliver.
* **Activation waves:** what Super Admin turns on for the business, when the business is ready. **Pilot one branch first, then all.**

| Wave | Super Admin turns on | Who starts using | Platform | Ready when |
|---|---|---|---|---|
| **W0. Foundation** | Core, Feature Control, Company Admin setup, roles, locations + geofences, employees, EN/BN | Super Admin, Company Admin, HR | Web | Org chart, employees, locations entered |
| **W1. People on the app** | `shifts.roster`, `attendance.gps_checkin`, `attendance.arrival_alert`, `attendance.no_show_alert`, `tasks.basic`, `tasks.team` | **Everyone** | Mobile + Web (HR) | Each staff member has the app installed and their device registered |
| **W2. Daily discipline** | `tasks.recurring` (checklists), `tasks.proof_photo`, `tasks.escalation`, `attendance.correction`, `attendance.proxy_checkin`, `leave.request` | Everyone | Mobile | W1 is stable for 2–4 weeks |
| **W3. Requests & stock** | `inventory.stock`, `req.supplies`, `req.raw_material`, `req.food_order`, approval matrix | Managers, Head Chefs, Warehouse | Mobile + Web | Items, units, opening stock entered |
| **W4. Fixed orders + Purchase** | `req.standing_order`, `purchase.core`, `purchase.cash_advance`, `purchase.petty_cash`, then `purchase.reimbursement`, `purchase.vendor_credit` | Branch Mgrs, Purchase, Buyers, Accounts | Mobile + Web | Vendors, limits and par lists set |
| **W5. Central Kitchen chain** | `production.*`, `dispatch.*`, `inventory.stock_count`, `inventory.wastage` | CK, drivers, branches | Mobile + Web | Recipes entered |
| **W6. Pay & performance** | `payroll.run` (**run 1 month alongside the current manual payroll**), `payroll.payslip_mobile`, `kpi.scorecard`, later `kpi.payroll_link` | HR, Accounts, everyone (payslip/score) | Web + Mobile | 2–3 months of clean attendance and task data |
| **W7. Accounting** | `acc.*` | Accounts | Web | Chart of accounts + opening balances entered |
| **W8. Catering** | `catering.*` | Catering team | Web + Mobile | Packages/menus entered |
| **W9. POS** | `pos.core` → `pos.kot`/`pos.kds` → `pos.recipe_deduction` (**pilot branch first**) | Cashiers, kitchens | Web | Menu, prices, printers ready |
| **Ongoing** | `mgmt.mobile_dashboard` cards appear automatically as each module goes live | Top management | Mobile | – |

**Build phases** follow the same order, with each phase finished and tested *before* its wave:

| Build phase | Delivers features for |
|---|---|
| B1 | Platform core, access gate, Feature Control, Company Admin, i18n, web shell, mobile shell → **W0** |
| B2 | Tasks, shifts, GPS attendance, arrival/no-show alerts, leave → **W1–W2** |
| B3 | Inventory, requisition engine, approval matrix, standing orders → **W3–W4** |
| B4 | Purchase, wallet, funding types, payment methods → **W4** |
| B5 | Production, recipes, dispatch → **W5** |
| B6 | Payroll, KPI → **W6** |
| B7 | Accounting + posting engine → **W7** |
| B8 | Catering → **W8** |
| B9 | POS (offline PWA), KOT/KDS → **W9** |
| Continuous | Management mobile dashboard & reports |

---

## 16. Open questions (round 4)

Answered: all staff have smartphones · proxy check-in rules · **you are the Super Admin**.
[DEMO_DATA.md](DEMO_DATA.md) uses **assumed** answers for everything below. Please correct anything that's wrong.

### Locations & operations
1. Is there **one central kitchen** and **one warehouse**? Does **Catering** cook in the CK? *(Demo assumes yes to all.)*
2. Do you have your **own vehicles/drivers** for CK → branch delivery? *(Demo assumes 2 drivers, 2 vans.)*

### Attendance
3. **Arrival alert:** should the team lead get **every check-in**, or only **late and not-arrived** staff? *(Demo: lead gets every check-in; manager gets late/no-show + a 10:30 summary.)*
4. **Off-site staff** (buyers, drivers, catering event staff): where do they check in? *(Demo: at base location; event staff at the event venue geofence.)*
5. Grace minutes, late-deduction rule, overtime rate? *(Demo: 10 min grace; 3 lates = 1 day's basic; OT = 1.5× hourly basic.)*

### HR & Payroll
6. Leave types and yearly days? *(Demo: casual 10, sick 14, annual 1 day per 18 working days.)*
7. Salary components, service-charge sharing, festival bonus, should KPI affect pay? *(Demo: basic 60% + house rent 30% + medical/conveyance 10%; two festival bonuses; KPI incentive ৳500–1,000.)*

### Money
8. Which **MFS providers** do you use? *(Demo: bKash + Nagad, transaction ID recorded manually.)*

### POS
9. Order types, third-party delivery apps, counter hardware? *(Demo: dine-in, takeaway, own delivery; 1 PC + receipt printer + kitchen printer per branch.)*

### Project
10. Who are the **Company Admins**? *(Demo: the GM and an Admin & IT Officer.)*
11. Who will develop it (in-house or agency), and do they prefer TypeScript (React Native) or Flutter for mobile?
12. Do you have a **target date for Wave 1** (tasks + attendance live)?
