# Demo Data: Sufra Food Ltd (fictional)

**Purpose:** a realistic sample company to explain the design, build wireframes on, and later turn into seed data for development and testing.
**Perspective:** **you are the Super Admin.**
**Demo "today":** Friday, 20 Nov 2026 · Currency: BDT (৳)
**Note:** all names, phone numbers, account numbers and prices are made up. Values marked *(assumed)* are guesses waiting for your real answers (see SYSTEM_PLAN.md §16).

---

## 1. Your Super Admin account

| Field | Value |
|---|---|
| User ID | `SA-0001` |
| Name | **You** (System Owner) |
| Role | **Super Admin**: all permissions |
| Login | Phone + OTP, plus 2-step verification required for Super Admin |
| Platform | Web (full) + Mobile (Management tab + everything) |
| Can do that nobody else can | Turn features on/off · create Company Admins · system settings (SMS, push, MFS list) · view the full audit log, including admin actions |

### Company Admins created by you

| User ID | Name | Position | Admin scope |
|---|---|---|---|
| `E-1002` | Farhana Islam | General Manager (Ops Head) | Company Admin: full |
| `E-1004` | Tanvir Ahmed | Admin & IT Officer | Company Admin: users, roles, master data (no approval-matrix edit) |

---

## 2. Company & locations

**Company:** Sufra Food Ltd · **Brand (branches):** Sufra Restaurant

| Code | Location | Type | Area | Geofence (lat, lng) | Radius | Staff |
|---|---|---|---|---|---|---|
| `HO` | Head Office | OFFICE | Tejgaon | 23.7639, 90.3925 | 80 m | 12 |
| `WH` | Central Warehouse | WAREHOUSE | Tejgaon I/A | 23.7600, 90.3990 | 120 m | 8 |
| `CK` | Central Kitchen | CENTRAL_KITCHEN | Tejgaon I/A | 23.7612, 90.4004 | 100 m | 22 |
| `CAT` | Sufra Catering | CATERING | Mohakhali | 23.7780, 90.4050 | 80 m | 14 |
| `BR-GUL` | Sufra Gulshan | BRANCH | Gulshan-2 | 23.7940, 90.4143 | 60 m | 18 |
| `BR-DHN` | Sufra Dhanmondi | BRANCH | Dhanmondi 27 | 23.7550, 90.3760 | 60 m | 16 |
| `BR-UTT` | Sufra Uttara | BRANCH | Uttara Sec-7 | 23.8690, 90.3980 | 60 m | 15 |
| `BR-MIR` | Sufra Mirpur | BRANCH | Mirpur-10 | 23.8070, 90.3685 | 60 m | 14 |
| `BR-BAN` | Sufra Banani | BRANCH | Banani-11 | 23.7937, 90.4066 | 60 m | 17 |
| `BR-BAS` | Sufra Bashundhara | BRANCH | Bashundhara R/A | 23.8150, 90.4270 | 60 m | 12 |
| | | | | | **Total** | **148** |

**Departments:** Management · HR · Accounts · Purchase · Warehouse/Store · Central Kitchen (Production, Prep, Dispatch) · Catering (Sales, Service) · Branch FOH · Branch Kitchen · Maintenance · Security & Cleaning

---

## 3. Roles (tiers)

| Role | Tier | Platform | Count |
|---|---|---|---|
| Super Admin | – | Web + Mobile | 1 (you) |
| Owner | T4 | Mobile + Web | 1 |
| General Manager | T4 | Mobile + Web | 1 |
| Department Manager (HR, Accounts, Purchase, Warehouse, Catering) | T3 | Web + Mobile | 5 |
| Head Chef (CK / Branch) | T3 | Mobile + Web | 7 |
| Branch Manager | T3 | Mobile (+ Web) | 6 |
| Supervisor / Team Lead | T2 | Mobile | 16 |
| Office staff (HR officer, Accounts officer, Admin officer) | T3-lite | Web | 5 |
| Cashier | Function | Web (POS) | 8 |
| Buyer | Function | Mobile | 3 |
| Driver | Function | Mobile | 2 |
| Storekeeper | Function | Mobile + Web | 8 |
| Worker (cook, helper, waiter, dishwasher, cleaner, guard) | T1 | Mobile | 85 |

---

## 4. People (key staff)

### 4.1 Management & head office

| ID | Name | Position | Location | Reports to |
|---|---|---|---|---|
| `E-1001` | Anwar Hossain | **Owner / Chairman** | HO | – |
| `E-1002` | Farhana Islam | **General Manager** · Company Admin | HO | Owner |
| `E-1004` | Tanvir Ahmed | Admin & IT Officer · Company Admin | HO | GM |
| `E-1010` | Mahbub Alam | Accounts Manager | HO | GM |
| `E-1011` | Sadia Rahman | Accounts Officer | HO | Mahbub Alam |
| `E-1020` | Nusrat Jahan | HR Manager | HO | GM |
| `E-1021` | Imran Hossain | HR Officer | HO | Nusrat Jahan |
| `E-1030` | Kamrul Hasan | **Purchase Manager** | HO | GM |
| `E-1031` | Rahim Uddin | Buyer | HO | Kamrul Hasan |
| `E-1032` | Jalal Ahmed | Buyer | HO | Kamrul Hasan |
| `E-1033` | Sohel Rana | Buyer | HO | Kamrul Hasan |

### 4.2 Warehouse, Central Kitchen, Catering

| ID | Name | Position | Location | Reports to |
|---|---|---|---|---|
| `E-1040` | Mizanur Rahman | **Warehouse Manager** | WH | GM |
| `E-1041` | Babul Mia | Storekeeper | WH | Mizanur Rahman |
| `E-1042` | Shahin Alam | Storekeeper | WH | Mizanur Rahman |
| `E-1050` | Abdul Karim | **CK Head Chef** | CK | GM |
| `E-1051` | Rafiq Islam | CK Sous Chef (Team Lead) | CK | Abdul Karim |
| `E-1052` | Monir Hossain | CK Helper | CK | Rafiq Islam |
| `E-1053` | Selim Reza | CK Helper | CK | Rafiq Islam |
| `E-1060` | Abul Kalam | Driver (Van 1) | CK | Abdul Karim |
| `E-1061` | Bablu Sheikh | Driver (Van 2) | CK | Abdul Karim |
| `E-1070` | Shirin Akter | **Catering Manager** | CAT | GM |
| `E-1071` | Arif Hossain | Catering Supervisor | CAT | Shirin Akter |

### 4.3 Branch managers

| ID | Name | Branch |
|---|---|---|
| `E-2001` | Tanjila Ahmed | Gulshan |
| `E-3001` | Rezaul Karim | Dhanmondi |
| `E-4001` | Moinul Haque | Uttara |
| `E-5001` | Habibur Rahman | Mirpur |
| `E-6001` | Sharmin Sultana | Banani |
| `E-7001` | Faisal Mahmud | Bashundhara |

### 4.4 Sufra Gulshan: full team (18)

```
Tanjila Ahmed (Branch Manager, E-2001)
├── Nazmul Hasan (Floor Supervisor / FOH Team Lead, E-2002)
│   ├── Mitu Akter (Cashier, E-2011)
│   ├── Rakib Hasan (Waiter, E-2012)
│   ├── Nayeem Islam (Waiter, E-2013)
│   ├── Polash Mia (Waiter, E-2014)
│   ├── Jony Ahmed (Waiter, E-2015)
│   ├── Rina Begum (Cleaner, E-2016)
│   └── Abul Kashem (Security Guard, E-2017)
├── Jamal Uddin (Branch Head Chef, E-2003)
│   └── Shafiq Ahmed (Sous Chef / Kitchen Team Lead, E-2004)
│       ├── Karim Mia (Cook, E-2005)
│       ├── Belal Hossain (Cook, E-2006)
│       ├── Sumon Ali (Tandoor Cook, E-2010)
│       ├── Rubel Mia (Kitchen Helper, E-2007)
│       ├── Shanto Das (Kitchen Helper, E-2008)
│       └── Alamgir Hossain (Dishwasher, E-2009)
└── Hasan Mahmud (Branch Storekeeper, E-2018)
```

---

## 5. Feature Control (your Super Admin screen)

Two views: the **Demo environment** (everything on, so all screens can be shown) and an example **Live rollout at month 2**.

| Feature | Demo env | Live (example, month 2) | Locations (live) |
|---|---|---|---|
| Core (users, roles, locations, language, audit) | ON | **ON** | All |
| `tasks.basic`, `tasks.team` | ON | **ON** | All |
| `tasks.recurring`, `tasks.proof_photo`, `tasks.escalation` | ON | **ON** | All |
| `shifts.roster`, `shifts.swap` | ON | **ON** | All |
| `attendance.gps_checkin`, `.arrival_alert`, `.no_show_alert` | ON | **ON** | All |
| `attendance.correction`, `attendance.proxy_checkin` | ON | **ON** | All |
| `attendance.remote_checkin` | ON | OFF | – |
| `leave.request`, `leave.balance` | ON | **PILOT** | Gulshan, CK |
| `inventory.stock` | ON | **PILOT** | WH, CK, Gulshan |
| `req.supplies`, `req.raw_material`, `req.food_order` | ON | **PILOT** | WH, CK, Gulshan |
| `req.standing_order` | ON | OFF | – |
| `purchase.core`, `purchase.cash_advance` | ON | **PILOT** | HO, WH |
| `purchase.petty_cash`, `.reimbursement`, `.vendor_credit` | ON | OFF | – |
| `production.*`, `dispatch.*` | ON | OFF | – |
| `kpi.scorecard` | ON | OFF | – |
| `payroll.*` | ON | OFF | – |
| `acc.*` | ON | OFF | – |
| `catering.*` | ON | OFF | – |
| `pos.*` | ON | OFF | – |
| `mgmt.mobile_dashboard` | ON | **ON** | (shows cards for live modules only) |

---

## 6. Role permissions (sample from Company Admin)

| Permission | Owner/GM | Branch Mgr | Head Chef | Supervisor | Worker | Buyer | Storekeeper |
|---|---|---|---|---|---|---|---|
| View own tasks / shift / attendance | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Assign tasks to own team | ✓ | ✓ | ✓ | ✓ | – | – | – |
| View team live attendance | ✓ | ✓ | ✓ | ✓ | – | – | – |
| Grant proxy check-in | – | ✓ | ✓ | – | – | – | – |
| Raise food order | – | ✓ | – | – | – | – | – |
| Raise raw-material / supplies request | – | ✓ | ✓ | – | – | – | ✓ |
| Approve requests (within limit) | ✓ | ✓ | ✓ | – | – | – | – |
| Receive delivery | – | ✓ | ✓ | – | – | – | ✓ |
| Purchase: my assignments & wallet | – | – | – | – | – | ✓ | – |
| Management dashboard (mobile) | ✓ | branch only | – | – | – | – | – |

---

## 7. Approval matrix & personal limits

### 7.1 Approval matrix

| # | Request type | Location | Amount (৳) | Level 1 | Level 2 | Level 3 | SLA / level |
|---|---|---|---|---|---|---|---|
| 1 | Food order | Any branch | any | *Auto* (raised by Branch Mgr) | – | – | – |
| 2 | Raw material | CK / Branch | any | *Auto* (raised by Head Chef) | – | – | – |
| 3 | Supplies | Any | 0 – 3,000 | Location manager | – | – | 2 h |
| 4 | Supplies | Any | > 3,000 | Location manager | GM | – | 4 h |
| 5 | Equipment | Any | 0 – 20,000 | Location manager | GM | – | 1 day |
| 6 | Equipment | Any | > 20,000 | Location manager | GM | Owner | 1 day |
| 7 | Purchase | Any | 0 – 5,000 | Purchase Mgr | – | – | 30 min |
| 8 | Purchase | Any | 5,001 – 50,000 | Purchase Mgr | GM | – | 2 h |
| 9 | Purchase | Any | > 50,000 | Purchase Mgr | GM | Owner | 4 h |
| 10 | Reimbursement | Any | 0 – 2,000 | Purchase Mgr | Accounts Mgr | – | 1 day |
| 11 | Leave | Any | – | Direct manager | HR Mgr | – | 1 day |
| 12 | Salary advance | Any | – | Direct manager | HR Mgr | GM | 2 days |
| 13 | Payroll run | All | – | HR Mgr | Accounts Mgr | Owner | 2 days |

### 7.2 Personal limits

| Person | Approval limit | Cash advance limit | Petty cash float | Own-money limit | POS discount |
|---|---|---|---|---|---|
| Anwar Hossain (Owner) | Unlimited | – | – | – | 100% |
| Farhana Islam (GM) | ৳ 2,00,000 | – | – | – | 30% |
| Kamrul Hasan (Purchase Mgr) | ৳ 50,000 | – | – | – | – |
| Branch Managers | ৳ 10,000 | – | ৳ 5,000 | – | 15% |
| Abdul Karim (CK Head Chef) | ৳ 10,000 | – | ৳ 10,000 | – | – |
| Mizanur Rahman (WH Mgr) | ৳ 10,000 | – | ৳ 5,000 | – | – |
| Rahim Uddin (Buyer) | – | ৳ 15,000 | – | ৳ 3,000 | – |
| Jalal Ahmed (Buyer) | – | ৳ 10,000 | – | ৳ 2,000 | – |
| Cashiers | – | – | – | – | 5% |

**Delegation (active):** Kamrul Hasan on leave 24–26 Nov → his purchase approvals go to **Farhana Islam (GM)**.

---

## 8. Payment methods & money accounts

**Payment methods (set by you):** Cash · Bank transfer · **bKash** · **Nagad** · Card (POS only). Rocket is defined but inactive *(assumed)*.

| Money account | Type | Opening balance (৳) |
|---|---|---|
| HO Main Cash | Cash | 2,50,000 |
| BRAC Bank Current ****4521 | Bank | 18,75,000 |
| City Bank Current ****7788 | Bank | 6,40,000 |
| bKash Merchant ****1122 | MFS | 1,12,000 |
| Nagad Merchant ****3344 | MFS | 48,500 |
| Petty Cash: CK (custodian: Abdul Karim) | Petty cash | 10,000 |
| Petty Cash: WH (custodian: Mizanur Rahman) | Petty cash | 5,000 |
| Petty Cash: each branch (custodian: Branch Mgr) | Petty cash | 5,000 × 6 |
| Cash Drawer: each branch POS | Cash | 3,000 float × 6 |
| Advance: Rahim Uddin | Employee advance | 10,000 |
| Advance: Jalal Ahmed | Employee advance | 8,000 |

---

## 9. Shifts & roster (Sufra Gulshan)

### 9.1 Shift templates

| Code | Shift | Time | Break |
|---|---|---|---|
| `M` | Morning | 08:00 – 16:00 | 30 min |
| `E` | Evening | 15:00 – 23:00 | 30 min |
| `S` | Split | 10:00 – 15:00 + 18:00 – 23:00 | – |
| `G` | General (managers) | 10:00 – 19:00 | 60 min |
| `GD` | Guard Day | 08:00 – 20:00 | 60 min |

**Rules *(assumed)*:** grace 10 min · 3 lates in a month = 1 day's basic deducted · OT = 1.5 × hourly basic, manager approval needed.

### 9.2 Today's attendance: Friday 20 Nov, Sufra Gulshan

| Employee | Shift | Check-in | Method | Status |
|---|---|---|---|---|
| Tanjila Ahmed | G 10:00 | 09:52 | SELF | ✅ On time |
| Nazmul Hasan | M 08:00 | 07:55 | SELF | ✅ On time |
| Jamal Uddin | G 10:00 | 10:04 | SELF | ✅ On time (within grace) |
| Shafiq Ahmed | M 08:00 | 07:58 | SELF | ✅ On time |
| Karim Mia | M 08:00 | 08:17 | SELF | ⚠ **Late 17 min** |
| Rubel Mia | M 08:00 | 08:01 | **PROXY by Shafiq** | ✅ On time |
| Alamgir Hossain | M 08:00 | – | – | 🔴 No-show → marked **Sick leave** by Tanjila 09:15 |
| Shanto Das | S 10:00 | 09:59 | SELF | ✅ On time |
| Sumon Ali | S 10:00 | 10:02 | SELF | ✅ On time |
| Mitu Akter | G 10:00 | 09:49 | SELF | ✅ On time |
| Rakib Hasan | S 10:00 | 10:06 | SELF | ✅ On time (within grace) |
| Rina Begum | M 08:00 | 07:50 | **PROXY by Nazmul** | ✅ On time |
| Abul Kashem | GD 08:00 | 07:45 | SELF | ✅ On time |
| Hasan Mahmud | M 08:00 | 07:59 | SELF | ✅ On time |
| Belal Hossain | E 15:00 | – | – | ⏳ Not due yet |
| Nayeem Islam | E 15:00 | – | – | ⏳ Not due yet |
| Jony Ahmed | E 15:00 | – | – | ⏳ Not due yet |
| Polash Mia | – | – | – | 💤 Weekly off |

### 9.3 Proxy check-in grants

| Grant | Granted by | Proxy person | Can check in (fixed list) | Valid | Reason |
|---|---|---|---|---|---|
| `PG-001` | Tanjila Ahmed (Branch Mgr) | Shafiq Ahmed (Sous Chef) | Rubel Mia, Alamgir Hossain | Permanent | Helpers often arrive together with the sous chef |
| `PG-002` | Tanjila Ahmed (Branch Mgr) | Nazmul Hasan (Supervisor) | Rina Begum | 15 – 30 Nov | Rina's phone is broken |
| `PG-003` | Abdul Karim (CK Head Chef) | Rafiq Islam (CK Sous Chef) | Monir Hossain, Selim Reza | Permanent | – |

*Max per proxy = 2 (Company Admin setting). Shafiq cannot check in Karim Mia, because Karim is not on his list.*

### 9.4 Alerts sent today (Gulshan)

| Time | To | Message |
|---|---|---|
| 07:55 → 08:01 | Shafiq (kitchen lead) / Nazmul (FOH lead) | Arrival alerts for each team member |
| 07:50 | Tanjila | "Nazmul checked in **Rina Begum (proxy)** · 07:50" |
| 08:01 | Tanjila | "Shafiq checked in **Rubel Mia (proxy)** · 08:01" |
| 08:10 | Shafiq, Tanjila | "🔴 **Alamgir Hossain** not checked in · shift 08:00" |
| 08:17 | Shafiq, Tanjila | "⚠ **Karim Mia** checked in late · 17 min" |
| 10:30 | Tanjila | "Summary: 13 in · 1 late · 1 leave · 3 not due · 1 off" |

---

## 10. Tasks (sample, Gulshan today)

| ID | Task | Assigned by → to | Due | Proof | Status |
|---|---|---|---|---|---|
| `T-8841` | Opening checklist (kitchen) | *Recurring* → on-shift kitchen team | 08:30 | Photo | ✅ Done 08:24 (Shafiq) |
| `T-8842` | Clean & oil fryer | Shafiq → Rubel Mia | 09:00 | Photo | ✅ Done 08:52 |
| `T-8843` | Cut onions 5 kg | Shafiq → Shanto Das | 11:30 | – | 🔵 Started |
| `T-8844` | Marinate tikka 8 kg | Jamal → Sumon Ali | 12:00 | – | ⚪ Assigned |
| `T-8845` | Deep clean freezer #2 | Tanjila → Kitchen team | 17:00 | Photo | ⚪ Assigned (team) |
| `T-8846` | Mop dining floor | Nazmul → Rina Begum | 09:30 | Photo | ⚠ **Overdue** → escalated to Nazmul 09:45 |
| `T-8847` | Check AC servicing quotation | Farhana (GM) → Tanjila | 21 Nov 17:00 | Note | ⚪ Assigned |
| `T-8848` | Receive CK delivery & confirm qty | *System* → Hasan Mahmud | 10:45 | – | ✅ Done 10:38 |

---

## 11. Items, stock & recipes

### 11.1 Items (sample)

| Code | Item (EN / BN) | Type | Unit | Buy unit | Reorder (WH) | WH stock | Last price |
|---|---|---|---|---|---|---|---|
| `RM-001` | Chinigura Rice / চিনিগুঁড়া চাল | Raw | kg | Sack 25 kg | 100 kg | 340 kg | ৳ 140/kg |
| `RM-002` | Broiler Chicken / ব্রয়লার মুরগি | Raw | kg | kg | 40 kg | 18 kg ⚠ | ৳ 210/kg |
| `RM-003` | Beef (bone-in) / গরুর মাংস | Raw | kg | kg | 30 kg | 55 kg | ৳ 780/kg |
| `RM-004` | Onion / পেঁয়াজ | Raw | kg | Sack 40 kg | 80 kg | 120 kg | ৳ 90/kg |
| `RM-005` | Ghee / ঘি | Raw | kg | Tin 4 kg | 8 kg | 20 kg | ৳ 1,400/kg |
| `RM-006` | Yogurt / টক দই | Raw | kg | kg | 10 kg | 15 kg | ৳ 220/kg |
| `RM-007` | Biryani Spice Mix / বিরিয়ানি মসলা | Raw | kg | Pack 1 kg | 3 kg | 6 kg | ৳ 1,800/kg |
| `RM-008` | Soybean Oil / সয়াবিন তেল | Raw | L | Can 5 L | 40 L | 85 L | ৳ 185/L |
| `CS-001` | Food Box 1000ml / ফুড বক্স | Consumable | pc | Carton 500 | 1,000 | 2,400 | ৳ 9/pc |
| `CS-002` | Gas Cylinder 35 kg | Consumable | pc | pc | 4 | 6 | ৳ 4,200/pc |
| `SF-001` | Chicken Biryani Base / চিকেন বিরিয়ানি | Semi (CK) | kg | – | – | (CK) | cost ৳ 249/kg |
| `SF-002` | Beef Tehari Base / বিফ তেহারি | Semi (CK) | kg | – | – | (CK) | cost ৳ 268/kg |
| `SF-003` | Mutton Kacchi Base / কাচ্চি | Semi (CK) | kg | – | – | (CK) | cost ৳ 520/kg |

### 11.2 Recipe: Chicken Biryani Base (`SF-001`), batch yield 10 kg · produced at **CK**

| Ingredient | Qty | Cost |
|---|---|---|
| Chinigura Rice | 3.5 kg | ৳ 490 |
| Broiler Chicken | 4.5 kg | ৳ 945 |
| Ghee | 0.4 kg | ৳ 560 |
| Onion | 1.2 kg | ৳ 108 |
| Yogurt | 0.5 kg | ৳ 110 |
| Biryani Spice Mix | 0.12 kg | ৳ 216 |
| Soybean Oil | 0.3 L | ৳ 56 |
| Salt | 0.08 kg | ৳ 4 |
| **Batch cost** | **10 kg** | **৳ 2,489 → ৳ 249/kg** |

**Menu item:** Chicken Biryani (plate) · ৳ 320 · uses 0.35 kg base + egg + salad → food cost ≈ ৳ 102 (**31.9%**)

### 11.3 Standing order: Sufra Gulshan → CK (par list)

| Item | Sat | Sun | Mon | Tue | Wed | Thu | Fri |
|---|---|---|---|---|---|---|---|
| Chicken Biryani Base (kg) | 14 | 12 | 12 | 12 | 14 | 18 | 20 |
| Beef Tehari Base (kg) | 10 | 8 | 8 | 8 | 10 | 12 | 15 |
| Chicken Rezala (kg) | 5 | 4 | 4 | 4 | 5 | 6 | 8 |
| Naan Dough (kg) | 6 | 5 | 5 | 5 | 6 | 8 | 10 |
| Borhani (L) | 15 | 12 | 12 | 12 | 15 | 20 | 25 |

Auto-created daily at **14:00** for the next day · editable by the Branch Manager until **20:00 cut-off**.

---

## 12. The chain reaction: Friday's biryani (end to end)

**Scenario:** Gulshan's Friday standing order, edited by the manager, causes a chicken purchase overnight.

| Time | Document | What happened | Who |
|---|---|---|---|
| Thu 14:00 | `FO-261119-014` | Standing order auto-created for Fri: Chicken Biryani Base **16 kg** (+ others), needed by Fri 11:00 | System |
| Thu 18:40 | `FO-261119-014` | Edited 16 → **20 kg** (Friday rush expected) | Tanjila (Gulshan) |
| Thu 20:00 | – | Cut-off → order locked. **CK plan for Fri: Biryani 65 kg** (Gulshan 20 + Banani 25 + Catering 20) | System |
| Thu 20:05 | `FO-261119-014` | **ACCEPTED** by CK | Abdul Karim |
| Thu 20:10 | `RM-261119-031` | Raw-material request auto-drafted from recipe × 65 kg − CK stock: rice 23 kg, **chicken 29.5 kg**, ghee 2.6 kg… | System |
| Thu 20:20 | `RM-261119-031` | Confirmed and sent to WH | Abdul Karim |
| Thu 20:35 | `RM-261119-031` | **PARTIALLY ACCEPTED**: chicken only 18 kg in stock, the rest OK | Mizanur (WH) |
| Thu 20:38 | `PR-261119-009` | Purchase request: chicken **12 kg**, est. ৳ 2,520 (linked to RM-031) | Mizanur (WH) |
| Thu 20:45 | `PR-261119-009` | **APPROVED** (৳ 2,520 < ৳ 5,000 → Purchase Mgr only) | Kamrul Hasan |
| Thu 20:46 | `PA-261119-009` | **Assigned** to Rahim Uddin · deadline **Fri 06:30** · funding: cash advance · method: cash | Kamrul Hasan |
| Thu 20:47 | `PA-261119-009` | Accepted 🔔 | Rahim Uddin |
| Fri 06:05 | `PA-261119-009` | Bought 12 kg @ ৳ 215 = **৳ 2,580** from Amin Poultry, Kawran Bazar · receipt photo uploaded · price check: +2.4% vs last (OK) | Rahim Uddin |
| Fri 06:25 | `GRN-261120-004` | Goods received at WH: 12 kg ✓ | Babul Mia |
| Fri 06:40 | `RM-261119-031` | Issued to CK: chicken 29.5 kg + rest → **RECEIVED** by CK 06:45 → **CLOSED** | Babul Mia → Rafiq Islam |
| Fri 07:00 | `PRD-261120-004` | Production: Biryani 65 kg · cooks: Rafiq's team · done 09:30 → CK stock +65 kg, ingredients used | Rafiq Islam |
| Fri 09:50 | `DSP-261120-002` | Dispatched to Gulshan + Banani · Van 1 · driver Abul Kalam | Abul Kalam |
| Fri 10:35 | `DSP-261120-002` | Arrived Gulshan | Abul Kalam |
| Fri 10:38 | `FO-261119-014` | **RECEIVED 20 kg** ✓ → **CLOSED** | Hasan Mahmud / Tanjila |

**What Tanjila saw on her phone at Thu 21:00:**
`FO-261119-014 · Accepted by CK · Waiting: WH → Buying chicken (Rahim Uddin, due Fri 06:30)`

---

## 13. Buyer wallet: Rahim Uddin (Fri 20 Nov, 07:00)

| | ৳ |
|---|---|
| Advance held | 10,000 |
| Spent: Onion 50 kg (19 Nov) | 4,300 ✓ approved |
| Spent: Chicken 12 kg (20 Nov) | 2,580 ⏳ receipt pending approval |
| **Balance to return / carry** | **3,120** |
| **Company owes me** (own money: gas cylinder refill, 18 Nov) | **1,200** ⏳ refund approved → bKash payout pending |

**Vendors (sample)**

| Vendor | Items | Terms | Outstanding |
|---|---|---|---|
| Amin Poultry, Kawran Bazar | Chicken | Cash | – |
| Karim Rice Agency, Babubazar | Rice | **Credit 15 days** | ৳ 47,600 (due 28 Nov) |
| Modina Spices | Spices, ghee | Credit 7 days | ৳ 12,300 |
| Fresh Veg Supplier, Jatrabari | Vegetables, onion | Cash / bKash | – |
| Dhaka Gas Supply | Gas cylinders | Cash | – |

---

## 14. Catering event (sample)

| Field | Value |
|---|---|
| Event | `CE-2611-007` · Wedding reception (Hasan family) |
| Date / time | Sat 28 Nov 2026 · 19:00 |
| Venue | Mohammadpur Community Centre · **temporary geofence** 100 m (staff check in here) |
| Guests / package | 300 · Royal Kacchi Package ৳ 650/head |
| Value | ৳ 1,95,000 · advance ৳ 97,500 received (**bank**) · balance due on event day |
| Status | CONFIRMED |
| **Auto-created on confirm** | Food order → CK: Mutton Kacchi Base 105 kg, Chicken Roast 300 pcs, Borhani 120 L, Firni 300 cups (needed 28 Nov 16:00) · Equipment request → WH: chafing dishes 12, plates 330, glasses 330 · Staff tasks: 12 servers 17:00–23:00, 1 supervisor (Arif) · Event-day checklist · Equipment return check 29 Nov |

---

## 15. HR samples

### 15.1 KPI: Karim Mia (Cook, Gulshan), November to date

| Metric | Value | Weight | Points |
|---|---|---|---|
| Task on-time rate | 101 / 112 = 90.2% | 40% | 36.1 |
| Completion rate | 110 / 112 = 98.2% | 20% | 19.6 |
| No-rework rate | 1 − 3/112 = 97.3% | 10% | 9.7 |
| Attendance & punctuality | (100% present + 90% on time) ÷ 2 = 95% | 30% | 28.5 |
| **Score** | | | **94 / 100** 🟢 |

Incentive bands *(assumed)*: ≥ 90 → ৳ 1,000 · 80–89 → ৳ 500 · < 80 → none.

### 15.2 Payslip: Karim Mia, October 2026 *(assumed rules)*

| Earnings | ৳ | Deductions | ৳ |
|---|---|---|---|
| Basic (60%) | 9,000 | Salary advance instalment | 1,000 |
| House rent (30%) | 4,500 | Late deduction (1 late) | 0 |
| Medical | 900 | | |
| Conveyance | 600 | | |
| Overtime 6 h × ৳ 65 | 390 | | |
| KPI incentive (Oct score 91) | 1,000 | | |
| **Gross** | **16,390** | **Total deductions** | **1,000** |
| **Net pay** | **৳ 15,390** · paid via **bKash ****5521** · 30 Oct 2026 | | |

---

## 16. POS day summary (sample): Sufra Gulshan, Thu 19 Nov

| | ৳ |
|---|---|
| Orders | 214 (dine-in 141 · takeaway 52 · own delivery 21) |
| Gross sales | 1,12,400 |
| Discounts | − 3,200 |
| Net sales | 1,09,200 |
| VAT (5%, *assumed*) | 5,460 |
| **Total collected** | **1,14,660** |
| Cash | 41,300 |
| Card | 28,900 |
| bKash | 31,260 |
| Nagad | 13,200 |
| Cashier close (Mitu Akter) | Cash counted ৳ 41,180 → **difference − ৳ 120** (flagged to Tanjila) |

---

## 17. What you see as Super Admin

### Web: Super Admin home
```
┌────────────────────────────────────────────────────────────────────────────┐
│ Sufra Food Ltd · Super Admin                              EN | বাং   You │
├──────────────┬─────────────────────────────────────────────────────────────┤
│ Feature      │ Rollout status:  ON 14 · PILOT 6 · OFF 22                   │
│ Control      │ Pilot health (Gulshan, CK, WH):                             │
│ Company      │   Requests closed on time 92% · Avg approval 18 min         │
│ Admins       │ Pending setup: POS (3/6) · Payroll (2/5) · Accounting (1/4) │
│ System       │                                                             │
│ Settings     │ Today: 148 staff · 131 checked in · 6 late · 3 proxy        │
│ Payment      │ Admin activity: Farhana edited approval rule #8 (09:12)     │
│ Methods      │                 Tanvir added 2 employees (Dhanmondi)        │
│ Audit Log    │ Alerts: 2 mock-GPS attempts blocked (Mirpur) [view]         │
│ Reports      │                                                             │
└──────────────┴─────────────────────────────────────────────────────────────┘
```

### Mobile: Management tab (Owner, GM and you)
```
┌──────────────────────────────────────┐
│ Group Today · Fri 20 Nov     EN|বাং  │
├──────────────────────────────────────┤
│ Attendance   131/148 in · 6 late     │
│              3 proxy · 2 leave       │
│ Approvals    4 pending  [→]          │
│ Requests     11 open · 1 delayed     │
│ Tasks        9 overdue (Mirpur 4)    │
│ Purchase     ৳ 6,880 spent today     │
│ Sales (Thu)  ৳ 6,48,300 all branches │  ← when POS is live
│ Cash ৳ 2.5L · Bank ৳ 25.2L · MFS ৳1.6L│  ← when Accounting is live
│ Catering     2 events this week      │
└──────────────────────────────────────┘
```
